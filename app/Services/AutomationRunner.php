<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Ids;
use App\Core\Logger;
use App\Core\Settings;
use App\Repositories\AuditLog;
use App\Repositories\AutomationConfigRepository;
use App\Repositories\AutomationJobRepository;
use App\Repositories\UserRepository;
use App\Storage\FileStore;
use App\Storage\LockTimeoutException;

/**
 * Daily motivation automation, driven by bin/automation-cron.php every 5 minutes.
 *
 * One run (under the automation/run.json lock, so runs never overlap):
 *  1. closes jobs left open on earlier IST days (no backlog is generated for them)
 *  2. plans today's jobs: one per enabled user per configured IST time (deterministic ids)
 *  3. processes due jobs (catching up on today's missed times) until the time budget is used
 *
 * Job flow: reserve content+speech allowance → generate text (saved before speech)
 *  → generate speech from that exact text → save MP3.
 *  - confirmed transient failures (429/500/502/503, connection never made) retry with
 *    backoff and Retry-After, up to automation.max_attempts per step; the saved text is reused
 *  - uncertain outcomes (timeout after sending, interrupted run) are never repeated automatically
 *  - every Azure call first takes a slot from DeploymentThrottle (tts: 3 per minute)
 *
 * Lock order: run lock → job day file → allowance day file.
 */
final class AutomationRunner
{
    public const LOCK = 'automation/run.json';
    public const HEARTBEAT = 'automation/heartbeat.json';
    private const LOOKBACK_DAYS = 7;

    /** @var callable(int): void */
    private $sleep;
    private string $runId = '';
    private array $stats = [];
    private AutomationJobRepository $jobs;
    private AutomationConfigRepository $config;
    private DailyAllowance $allowance;
    private DeploymentThrottle $throttle;
    private ContentGenerator $content;
    private SpeechGenerator $speech;

    public function __construct(private FileStore $fs, private ?AzureClient $azure = null, ?callable $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $seconds): void { sleep($seconds); };
        $this->jobs = new AutomationJobRepository($fs);
        $this->config = new AutomationConfigRepository($fs);
        $this->allowance = new DailyAllowance($fs);
        $this->throttle = new DeploymentThrottle($fs);
        $this->content = new ContentGenerator($fs);
        $this->speech = new SpeechGenerator($fs);
    }

    /** One cron run. Returns stats, or ['skipped' => true] when another run holds the lock. */
    public function run(int $budgetSec = 240): array
    {
        try {
            return $this->fs->withLock(self::LOCK, true, fn() => $this->runLocked(max(10, $budgetSec)), 1.0);
        } catch (LockTimeoutException) {
            $this->beat(['last_skipped_at' => Clock::nowIso()]);
            return ['skipped' => true];
        }
    }

    public function heartbeat(): array
    {
        return $this->fs->read(self::HEARTBEAT);
    }

    private function runLocked(int $budgetSec): array
    {
        $this->runId = bin2hex(random_bytes(6));
        $this->stats = ['planned' => 0, 'content_success' => 0, 'speech_success' => 0, 'completed' => 0, 'failed_attempts' => 0,
            'retries_scheduled' => 0, 'failed' => 0, 'uncertain' => 0, 'blocked' => 0, 'expired' => 0];
        $deadline = Clock::now()->getTimestamp() + $budgetSec;
        $this->beat(['last_started_at' => Clock::nowIso(), 'last_run_id' => $this->runId, 'last_status' => 'running',
            'pid' => getmypid(), 'timezone' => Clock::tz()->getName()]);
        try {
            $today = Clock::businessDate();
            $this->closePastDays($today);
            $this->allowance->reconcileStale($today, max(600, Settings::int('azure.request_timeout_sec', 120) + 300));
            $this->plan($today);
            $this->process($today, $deadline);
        } catch (\Throwable $e) {
            Logger::error('automation: run failed: ' . $e->getMessage(), ['run' => $this->runId]);
            $this->beat(['last_finished_at' => Clock::nowIso(), 'last_status' => 'error',
                'last_error' => Logger::redact(mb_substr($e->getMessage(), 0, 300)), 'last_stats' => $this->stats]);
            throw $e;
        }
        $this->beat(['last_finished_at' => Clock::nowIso(), 'last_status' => 'ok', 'last_error' => null, 'last_stats' => $this->stats]);
        return $this->stats;
    }

    // ------------------------------------------------------------------ planning

    /** Create today's jobs for enabled users; drop not-yet-started jobs that left the schedule. */
    public function plan(string $date): void
    {
        $cfg = $this->config->get();
        $users = new UserRepository($this->fs);
        $enabled = array_values(array_filter(array_map('intval', array_keys($cfg['users'])),
            fn(int $uid) => ($users->find($uid)['status'] ?? '') === 'active'));
        $times = $cfg['times'];
        $themes = $cfg['themes'];
        $planned = $this->jobs->mutateDay($date, function (array &$jobs) use ($date, $enabled, $times, $themes) {
            $n = 0;
            foreach ($jobs as $id => $j) {
                if ($j['status'] === 'scheduled' && (!in_array((int)$j['user_id'], $enabled, true) || !in_array($j['time'], $times, true))) {
                    unset($jobs[$id]);
                }
            }
            foreach ($enabled as $uid) {
                $used = [];
                foreach ($jobs as $j) {
                    if ((int)$j['user_id'] === $uid && $j['status'] !== 'scheduled') {
                        $used[] = $j['theme'];
                    }
                }
                foreach ($times as $i => $time) {
                    $id = AutomationJobRepository::jobId($date, $uid, $time);
                    if (isset($jobs[$id]) && $jobs[$id]['status'] !== 'scheduled') {
                        continue;
                    }
                    $theme = self::pickTheme($themes, $date, $i, $used);
                    $used[] = $theme;
                    if (isset($jobs[$id])) {
                        $jobs[$id]['theme'] = $theme;
                        continue;
                    }
                    $jobs[$id] = self::newJob($id, $date, $uid, $time, $theme);
                    $n++;
                }
            }
            return $n;
        });
        $this->stats['planned'] = ($this->stats['planned'] ?? 0) + $planned;
    }

    /** A different theme per run: rotate through the list by day, skipping themes already used today. */
    public static function pickTheme(array $themes, string $date, int $slotIndex, array $used): string
    {
        $n = count($themes);
        $day = intdiv((int)(new \DateTimeImmutable($date . ' 00:00:00', Clock::utc()))->getTimestamp(), 86400);
        $start = ($day * AutomationConfigRepository::MAX_TIMES + $slotIndex) % $n;
        for ($k = 0; $k < $n; $k++) {
            $t = $themes[($start + $k) % $n];
            if (!in_array($t, $used, true)) {
                return $t;
            }
        }
        return $themes[$start];
    }

    private static function newJob(string $id, string $date, int $uid, string $time, string $theme): array
    {
        $now = Clock::nowIso();
        return [
            'id' => $id, 'user_id' => $uid, 'date' => $date, 'time' => $time,
            'due_at' => Clock::toIso(Clock::localAt($date, $time)),
            'status' => 'scheduled', 'theme' => $theme, 'language' => null, 'voice' => null,
            'round' => 1, 'reserved' => false,
            'content' => self::freshStep() + ['text' => null, 'word_count' => null, 'quote_warning' => false],
            'speech' => self::freshStep() + ['audio' => null, 'bytes' => null],
            'attempts' => [], 'message' => null,
            'started_at' => null, 'completed_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private static function freshStep(): array
    {
        return ['state' => 'pending', 'attempts' => 0, 'request_id' => null, 'claimed_by' => null, 'claimed_at' => null,
            'next_attempt_at' => null, 'usage_log_id' => null, 'error' => null];
    }

    private static function allowanceKey(array $job): string
    {
        return 'auto:' . $job['id'] . ':' . (int)$job['round'];
    }

    // ------------------------------------------------------------------ processing

    private function process(string $today, int $deadline): void
    {
        $done = [];
        while (Clock::now()->getTimestamp() < $deadline) {
            $now = Clock::now()->getTimestamp();
            $due = array_filter($this->jobs->forDay($today), fn(array $j) => !isset($done[$j['id']])
                && !in_array($j['status'], AutomationJobRepository::TERMINAL, true)
                && Clock::parseIso($j['due_at'])->getTimestamp() <= $now);
            if ($due === []) {
                return;
            }
            $progress = false;
            $wait = null;
            foreach (array_keys($due) as $id) {
                if (Clock::now()->getTimestamp() >= $deadline) {
                    return;
                }
                $r = $this->step($id);
                if ($r === true) {
                    $progress = true;
                } elseif (is_int($r)) {
                    $wait = min($wait ?? $r, $r);
                } else {
                    $done[$id] = true;
                }
            }
            if ($progress) {
                continue;
            }
            if ($wait === null || Clock::now()->getTimestamp() + $wait >= $deadline) {
                return;
            }
            ($this->sleep)(max(1, $wait));
        }
    }

    /**
     * Advance one job by at most one Azure call.
     * @return bool|int|null true = progressed, int = seconds to wait, null = nothing more this run
     */
    private function step(string $id): bool|int|null
    {
        $job = $this->jobs->find($id);
        if ($job === null || in_array($job['status'], AutomationJobRepository::TERMINAL, true)) {
            return null;
        }
        foreach (['content', 'speech'] as $s) {
            // In flight when an earlier run died (we hold the run lock, so it is not running now).
            if ($job[$s]['state'] === 'requesting' && $job[$s]['claimed_by'] !== $this->runId) {
                $this->stepUncertain($job, $s, 'A cron run stopped while this request was in flight, so Azure may have processed it.');
                return true;
            }
        }
        $stepName = $job['content']['state'] === 'success' ? 'speech' : 'content';
        $next = $job[$stepName]['next_attempt_at'];
        if ($job['status'] === 'waiting_retry' && $next !== null) {
            $left = Clock::parseIso($next)->getTimestamp() - Clock::now()->getTimestamp();
            if ($left > 0) {
                return $left;
            }
        }

        $why = $stepName === 'content'
            ? ($this->content->unavailableReason() ?? $this->speech->unavailableReason())
            : $this->speech->unavailableReason();
        if ($why !== null) {
            return $this->block($job, 'Waiting for configuration: ' . $why);
        }

        if (!$job['reserved']) {
            $user = (new UserRepository($this->fs))->find((int)$job['user_id']);
            if ($user === null || $user['status'] !== 'active') {
                return $this->finish($job, 'skipped', 'Skipped because the user account is disabled.');
            }
            if (!$this->config->isEnabledFor((int)$job['user_id'])) {
                return $this->finish($job, 'skipped', 'Skipped because automation was turned off for this user before the job started.');
            }
            $kinds = $stepName === 'content' ? ['content', 'speech'] : ['speech'];
            try {
                $this->allowance->reserve((int)$job['user_id'], $kinds, 'auto', self::allowanceKey($job), $job['date']);
            } catch (AllowanceException $e) {
                return $this->block($job, self::blockedMessage($job, $e));
            }
            $cfg = $this->config->get();
            $job = $this->jobs->change($id, fn(array $j) => $j['reserved'] ? null : array_merge($j, [
                'reserved' => true, 'status' => 'queued', 'message' => null,
                'language' => $cfg['language'], 'voice' => $cfg['voice'],
                'started_at' => $j['started_at'] ?? Clock::nowIso(),
            ]));
        }
        return $stepName === 'content' ? $this->runContent($job) : $this->runSpeech($job);
    }

    private function runContent(array $job): bool|int|null
    {
        $cfg = $this->content->config();
        $slot = $this->throttle->acquire($cfg);
        if (!$slot['allowed']) {
            return $slot['wait'];
        }
        $job = $this->claim($job, 'content');
        if ($job === null) {
            return null;
        }
        try {
            $res = $this->azure()->generateContent($cfg, $this->messages($job), (int)$job['user_id']);
        } catch (\Throwable $e) {
            Logger::error('automation: content request interrupted: ' . $e->getMessage(), ['job' => $job['id']]);
            $this->stepUncertain($job, 'content', 'The request was interrupted, so Azure may have processed it.');
            return true;
        }
        if ($res['outcome'] === 'success') {
            $text = trim($res['text']);
            $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            // Persist the text before any speech request, so speech retries reuse it.
            $this->jobs->change($job['id'], function (array $j) use ($text, $words, $res) {
                $j['content'] = array_merge($j['content'], ['state' => 'success', 'text' => $text, 'word_count' => $words,
                    'quote_warning' => self::looksLikeQuotation($text), 'usage_log_id' => $res['usage_log_id'], 'error' => null]);
                $j['attempts'][] = self::attemptRow('content', $j['content']['attempts'], $res);
                $j['status'] = 'queued';
                return $j;
            });
            $this->allowance->settle((int)$job['user_id'], 'content', self::allowanceKey($job), 'success', $job['date']);
            $this->stats['content_success']++;
            return true;
        }
        return $this->handleProblem($job, 'content', $res, $cfg);
    }

    private function runSpeech(array $job): bool|int|null
    {
        $text = (string)$job['content']['text'];
        if ($text === '' || mb_strlen($text) > SpeechGenerator::MAX_INPUT) {
            $this->allowance->settle((int)$job['user_id'], 'speech', self::allowanceKey($job), 'released', $job['date']);
            return $this->finish($job, 'failed', 'The saved text is empty or longer than 4,096 characters, so it cannot be converted to speech. The text is kept.', ['speech' => ['state' => 'failed']]);
        }
        $cfg = $this->speech->config();
        $slot = $this->throttle->acquire($cfg);
        if (!$slot['allowed']) {
            return $slot['wait'];
        }
        $job = $this->claim($job, 'speech');
        if ($job === null) {
            return null;
        }
        $rel = AutomationJobRepository::audioRel((int)$job['user_id'], $job['id']);
        try {
            // Exactly the saved text: never regenerated, never edited.
            $res = $this->azure()->generateSpeech($cfg, $text, (string)$job['voice'], (int)$job['user_id']);
            if ($res['outcome'] === 'success') {
                $this->fs->atomicPut($this->fs->path($rel), $res['audio']);
            }
        } catch (\Throwable $e) {
            Logger::error('automation: speech request interrupted: ' . $e->getMessage(), ['job' => $job['id']]);
            $this->stepUncertain($job, 'speech', 'The request was interrupted or the audio could not be saved, so Azure may have processed it.');
            return true;
        }
        if ($res['outcome'] === 'success') {
            $bytes = strlen($res['audio']);
            $this->jobs->change($job['id'], function (array $j) use ($rel, $bytes, $res) {
                $j['speech'] = array_merge($j['speech'], ['state' => 'success', 'audio' => $rel, 'bytes' => $bytes,
                    'usage_log_id' => $res['usage_log_id'], 'error' => null]);
                $j['attempts'][] = self::attemptRow('speech', $j['speech']['attempts'], $res);
                $j['status'] = 'completed';
                $j['message'] = null;
                $j['completed_at'] = Clock::nowIso();
                return $j;
            });
            $this->allowance->settle((int)$job['user_id'], 'speech', self::allowanceKey($job), 'success', $job['date']);
            $this->stats['speech_success']++;
            $this->stats['completed']++;
            Logger::info('automation: job completed', ['job' => $job['id']]);
            return true;
        }
        return $this->handleProblem($job, 'speech', $res, $cfg);
    }

    /** Atomically move a step to "requesting". Returns the claimed job, or null if it was not claimable. */
    private function claim(array $job, string $step): ?array
    {
        $requestId = Ids::uuid();
        $claimed = $this->jobs->change($job['id'], function (array $j) use ($step, $requestId) {
            if (!in_array($j[$step]['state'], ['pending', 'retry'], true) || !$j['reserved']) {
                return null;
            }
            $j[$step] = array_merge($j[$step], ['state' => 'requesting', 'attempts' => $j[$step]['attempts'] + 1,
                'request_id' => $requestId, 'claimed_by' => $this->runId, 'claimed_at' => Clock::nowIso(), 'next_attempt_at' => null]);
            $j['status'] = 'running';
            return $j;
        });
        return ($claimed[$step]['request_id'] ?? null) === $requestId ? $claimed : null;
    }

    /** Failed or unknown Azure outcome for a step. */
    private function handleProblem(array $job, string $step, array $res, array $cfg): bool
    {
        $label = $step === 'content' ? 'Text' : 'Speech';
        $error = self::errorText($res);
        if ($res['outcome'] !== 'failed') {
            $this->stepUncertain($job, $step, "No answer was received ({$error}), so Azure may have processed it.", $res);
            return true;
        }
        $this->stats['failed_attempts']++;
        $attempt = (int)$job[$step]['attempts'];
        $max = max(1, min(5, Settings::int('automation.max_attempts', 3)));
        if ((int)$res['http_status'] === 429) {
            $this->throttle->cooldown($cfg, $res['retry_after'] ?? 60);
        }
        if (self::isTransient($res) && $attempt < $max) {
            $wait = max(min(900, 60 * 2 ** ($attempt - 1)), (int)($res['retry_after'] ?? 0));
            $at = Clock::now()->modify("+{$wait} seconds");
            $this->jobs->change($job['id'], function (array $j) use ($step, $res, $error, $at, $label, $attempt, $max) {
                $j[$step] = array_merge($j[$step], ['state' => 'retry', 'next_attempt_at' => Clock::toIso($at), 'error' => $error]);
                $j['attempts'][] = self::attemptRow($step, $attempt, $res);
                $j['status'] = 'waiting_retry';
                $j['message'] = "{$label} attempt {$attempt} of {$max} failed ({$error}). Retrying automatically at {$at->format('H:i')} IST"
                    . ($step === 'speech' ? ' with the saved text.' : '.');
                return $j;
            });
            $this->stats['retries_scheduled']++;
            return true;
        }
        $hint = self::hintFor($res);
        $kept = $step === 'speech' ? ' The generated text is kept.' : '';
        $this->jobs->change($job['id'], function (array $j) use ($step, $res, $error, $label, $hint, $kept, $attempt) {
            $j[$step] = array_merge($j[$step], ['state' => 'failed', 'error' => $error, 'usage_log_id' => $res['usage_log_id'] ?? null]);
            $j['attempts'][] = self::attemptRow($step, $attempt, $res);
            $j['status'] = 'failed';
            $j['message'] = "{$label} generation failed after {$attempt} attempt(s): {$error}.{$kept} {$hint}";
            return $j;
        });
        $this->allowance->settle((int)$job['user_id'], $step, self::allowanceKey($job), 'failed', $job['date']);
        if ($step === 'content') {
            $this->allowance->settle((int)$job['user_id'], 'speech', self::allowanceKey($job), 'released', $job['date']);
        }
        $this->stats['failed']++;
        Logger::warning('automation: job failed', ['job' => $job['id'], 'step' => $step, 'error' => $error]);
        return true;
    }

    /** Outcome unknown: keep the reservation, never repeat the request automatically. */
    private function stepUncertain(array $job, string $step, string $why, ?array $res = null): void
    {
        $label = $step === 'content' ? 'text' : 'speech';
        $this->jobs->change($job['id'], function (array $j) use ($step, $why, $res, $label) {
            $j[$step] = array_merge($j[$step], ['state' => 'unknown', 'error' => $why, 'next_attempt_at' => null,
                'usage_log_id' => $res['usage_log_id'] ?? $j[$step]['usage_log_id']]);
            $j['attempts'][] = $res !== null ? self::attemptRow($step, (int)$j[$step]['attempts'], $res)
                : ['step' => $step, 'attempt' => (int)$j[$step]['attempts'], 'at' => Clock::nowIso(), 'outcome' => 'unknown',
                   'http_status' => null, 'error_code' => 'interrupted', 'error' => $why];
            $j['status'] = 'uncertain';
            $j['message'] = "The {$label} request outcome is uncertain: {$why} It will not be retried automatically. "
                . 'An administrator can check the Azure usage log and retry once they confirm nothing was produced.'
                . ($step === 'speech' ? ' The text is kept.' : '');
            return $j;
        });
        $this->allowance->settle((int)$job['user_id'], $step, self::allowanceKey($job), 'unknown', $job['date']);
        if ($step === 'content') {
            $this->allowance->settle((int)$job['user_id'], 'speech', self::allowanceKey($job), 'released', $job['date']);
        }
        $this->stats['uncertain']++;
        Logger::warning('automation: job uncertain', ['job' => $job['id'], 'step' => $step]);
    }

    private function block(array $job, string $message): ?bool
    {
        $this->jobs->change($job['id'], fn(array $j) => in_array($j['status'], AutomationJobRepository::TERMINAL, true) || $j['message'] === $message && $j['status'] === 'blocked'
            ? null : array_merge($j, ['status' => 'blocked', 'message' => $message]));
        $this->stats['blocked']++;
        return null;
    }

    private function finish(array $job, string $status, string $message, array $merge = []): bool
    {
        $this->jobs->change($job['id'], function (array $j) use ($status, $message, $merge) {
            foreach ($merge as $k => $v) {
                $j[$k] = array_merge($j[$k], $v);
            }
            return array_merge($j, ['status' => $status, 'message' => $message, 'completed_at' => Clock::nowIso()]);
        });
        if (isset($this->stats[$status])) {
            $this->stats[$status]++;
        }
        return true;
    }

    private static function blockedMessage(array $job, AllowanceException $e): string
    {
        $s = $e->summary;
        $label = $e->kind === 'speech' ? 'speech' : 'content';
        $parts = [];
        foreach (['manual_used' => 'used manually', 'auto_used' => 'used by automation',
                  'manual_reserved' => 'manual still processing/unverified', 'auto_reserved' => 'automatic still processing/unverified'] as $k => $txt) {
            if ($s[$k] > 0) {
                $parts[] = "{$s[$k]} {$txt}";
            }
        }
        $why = $s['manual_used'] + $s['manual_reserved'] > 0
            ? ' Manual requests consumed the remaining allowance, so automation cannot complete this text/audio pair.'
            : '';
        return "Not generated: today's {$e->limit} {$label} generations are already taken (" . implode(', ', $parts) . ").{$why}"
            . ' Nothing was sent to Azure. The allowance resets at midnight IST; this job is checked again if capacity frees up today.';
    }

    // ------------------------------------------------------------------ earlier days

    /** Jobs still open on earlier IST days are closed, never caught up. Reservations they held are released. */
    private function closePastDays(string $today): void
    {
        $d = Clock::localAt($today, '00:00');
        for ($i = 1; $i <= self::LOOKBACK_DAYS; $i++) {
            $date = $d->modify("-{$i} day")->format('Y-m-d');
            if (!$this->fs->exists(AutomationJobRepository::file($date))) {
                continue;
            }
            $settle = [];
            $this->jobs->mutateDay($date, function (array &$jobs) use (&$settle) {
                foreach ($jobs as $id => $j) {
                    if (in_array($j['status'], AutomationJobRepository::TERMINAL, true) || ($j['status'] === 'blocked' && !$j['reserved'])) {
                        continue;
                    }
                    $key = self::allowanceKey($j);
                    $uncertain = null;
                    foreach (['content', 'speech'] as $s) {
                        if ($j[$s]['state'] === 'requesting') {
                            $j[$s]['state'] = 'unknown';
                            $uncertain = $s;
                            $settle[] = [(int)$j['user_id'], $s, $key, 'unknown', $j['date']];
                        } elseif ($j['reserved'] && in_array($j[$s]['state'], ['pending', 'retry'], true)) {
                            $settle[] = [(int)$j['user_id'], $s, $key, 'released', $j['date']];
                        }
                    }
                    if ($uncertain !== null) {
                        $j['status'] = 'uncertain';
                        $j['message'] = "A cron run stopped while the {$uncertain} request was in flight, so Azure may have processed it. It will not be retried automatically.";
                    } else {
                        $j['status'] = 'expired';
                        $j['message'] = $j['content']['state'] === 'success'
                            ? 'The IST day ended before the audio could be generated. The text is kept; earlier days are not caught up.'
                            : 'The IST day ended before this job could run. Missed earlier days are not caught up.';
                        $this->stats['expired']++;
                    }
                    $j['updated_at'] = Clock::nowIso();
                    $jobs[$id] = $j;
                }
            });
            foreach ($settle as [$uid, $kind, $key, $status, $day]) {
                $this->allowance->settle($uid, $kind, $key, $status, $day);
            }
        }
    }

    // ------------------------------------------------------------------ admin actions

    /**
     * Administrator retry of today's failed or uncertain job. For an uncertain step the
     * administrator confirms Azure produced nothing, which releases its reservation.
     */
    public function retry(string $id, ?int $adminId): array
    {
        $parsed = AutomationJobRepository::parseId($id) ?? throw new \InvalidArgumentException('Job not found.');
        if ($parsed['date'] !== Clock::businessDate()) {
            throw new \InvalidArgumentException('Only today\'s jobs can be retried. Earlier days are not caught up.');
        }
        $settle = null;
        $job = $this->jobs->change($id, function (array $j) use (&$settle) {
            if (!in_array($j['status'], ['failed', 'uncertain'], true)) {
                throw new \InvalidArgumentException('Only failed or uncertain jobs can be retried.');
            }
            $step = $j['content']['state'] === 'success' ? 'speech' : 'content';
            if ($j[$step]['state'] === 'unknown') {
                $settle = [(int)$j['user_id'], $step, self::allowanceKey($j), 'released', $j['date']];
            }
            $j[$step] = array_merge(self::freshStep(), array_intersect_key($j[$step], array_flip(['text', 'word_count', 'quote_warning', 'audio', 'bytes'])));
            if ($step === 'content') {
                $j['speech'] = array_merge($j['speech'], self::freshStep());
            }
            $j['round'] = (int)$j['round'] + 1;
            $j['reserved'] = false;
            $j['status'] = 'queued';
            $j['completed_at'] = null;
            $j['message'] = 'Retry requested by an administrator. It runs on the next cron run.';
            return $j;
        }) ?? throw new \InvalidArgumentException('Job not found.');
        if ($settle !== null) {
            $this->allowance->settle(...$settle);
        }
        (new AuditLog($this->fs))->record('automation.job_retried', $adminId, 'automation_job', $id);
        return $job;
    }

    /**
     * The next scheduled runs (IST) for enabled users, from today's job records and the schedule.
     * @return list<array{date: string, time: string, user_id: int, status: string, theme: ?string}>
     */
    public function upcoming(int $limit = 12): array
    {
        $cfg = $this->config->get();
        $users = array_map('intval', array_keys($cfg['users']));
        $out = [];
        $now = Clock::now();
        foreach ([0, 1] as $offset) {
            $date = $now->modify("+{$offset} day")->format('Y-m-d');
            $jobs = $offset === 0 ? $this->jobs->forDay($date) : [];
            foreach ($cfg['times'] as $time) {
                foreach ($users as $uid) {
                    $job = $jobs[AutomationJobRepository::jobId($date, $uid, $time)] ?? null;
                    $at = Clock::localAt($date, $time);
                    if ($job === null && $at <= $now) {
                        $out[] = ['date' => $date, 'time' => $time, 'user_id' => $uid, 'status' => 'due', 'theme' => null];
                    } elseif ($job === null) {
                        $out[] = ['date' => $date, 'time' => $time, 'user_id' => $uid, 'status' => 'upcoming', 'theme' => null];
                    } elseif (!in_array($job['status'], AutomationJobRepository::TERMINAL, true)) {
                        $out[] = ['date' => $date, 'time' => $time, 'user_id' => $uid, 'status' => $job['status'], 'theme' => $job['theme']];
                    }
                }
            }
        }
        return array_slice($out, 0, $limit);
    }

    /**
     * Today's configured times for one user with each slot's state (job status, 'due' or
     * 'upcoming'), plus the next time a message is expected. Read-only; no Azure calls.
     * @return array{slots: list<array{time: string, status: string, job: ?array}>, next: ?array{date: string, time: string}}
     */
    public function slotsForUser(int $uid): array
    {
        $cfg = $this->config->get();
        if (!isset($cfg['users'][(string)$uid]) || !$cfg['times']) {
            return ['slots' => [], 'next' => null];
        }
        $now = Clock::now();
        $today = Clock::businessDate();
        $jobs = $this->jobs->forDay($today);
        $slots = [];
        $next = null;
        foreach ($cfg['times'] as $time) {
            $job = $jobs[AutomationJobRepository::jobId($today, $uid, $time)] ?? null;
            $future = Clock::localAt($today, $time) > $now;
            $status = $job['status'] ?? ($future ? 'upcoming' : 'due');
            if ($next === null && $future && !in_array($status, AutomationJobRepository::TERMINAL, true)) {
                $next = ['date' => $today, 'time' => $time];
            }
            $slots[] = ['time' => $time, 'status' => $status, 'job' => $job];
        }
        $next ??= ['date' => $now->modify('+1 day')->format('Y-m-d'), 'time' => $cfg['times'][0]];
        return ['slots' => $slots, 'next' => $next];
    }

    // ------------------------------------------------------------------ helpers

    private function azure(): AzureClient
    {
        return $this->azure ??= new AzureClient($this->fs);
    }

    private function messages(array $job): array
    {
        $earlier = [];
        foreach ($this->jobs->forDay($job['date']) as $j) {
            if ((int)$j['user_id'] === (int)$job['user_id'] && $j['id'] !== $job['id'] && $j['content']['state'] === 'success') {
                $earlier[] = '- ' . mb_substr((string)$j['content']['text'], 0, 120) . '…';
            }
        }
        $language = (string)$job['language'];
        $theme = (string)$job['theme'];
        $system = "You write short, original motivational messages that will be read aloud as audio.\n"
            . "Rules:\n"
            . "- Write only in {$language}.\n"
            . "- Length: 100 to 150 words.\n"
            . "- Tone: encouraging, warm and natural, as if speaking directly to the listener.\n"
            . "- Do not include quotations, sayings or proverbs, and never attribute words to any real, historical or fictional person.\n"
            . "- Do not invent facts, statistics or studies.\n"
            . "- Plain spoken text only: no title, headings, lists, emojis, hashtags or markdown.";
        $user = "Write today's {$job['time']} motivational message on the theme \"{$theme}\".";
        if ($earlier !== []) {
            $user .= "\nIt must be clearly different from today's earlier messages, which began:\n" . implode("\n", $earlier);
        }
        return [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
    }

    private static function attemptRow(string $step, int $attempt, array $res): array
    {
        return ['step' => $step, 'attempt' => $attempt, 'at' => Clock::nowIso(), 'outcome' => $res['outcome'],
            'http_status' => $res['http_status'] ?: null, 'error_code' => $res['error_code'] ?? null,
            'error' => $res['outcome'] === 'success' ? null : self::errorText($res), 'usage_log_id' => $res['usage_log_id'] ?? null];
    }

    /** Confirmed failures worth retrying: throttling, Azure-side errors, or a connection that was never made. */
    public static function isTransient(array $res): bool
    {
        if (($res['outcome'] ?? '') !== 'failed') {
            return false;
        }
        return in_array((int)$res['http_status'], [429, 500, 502, 503], true)
            || str_starts_with((string)($res['error_code'] ?? ''), 'transport_');
    }

    private static function errorText(array $res): string
    {
        $msg = trim((string)($res['error_message'] ?? '')) ?: 'Azure request failed';
        $status = (int)($res['http_status'] ?? 0);
        return Logger::redact(mb_substr(rtrim($msg, '.') . ($status ? " (HTTP {$status})" : ''), 0, 300));
    }

    private static function hintFor(array $res): string
    {
        return match (true) {
            in_array((int)$res['http_status'], [401, 403], true) => 'Check the API key variable for this deployment in the server .env file.',
            (int)$res['http_status'] === 404 => 'Check the deployment name and endpoint under Azure deployments.',
            (int)$res['http_status'] === 400 => 'Azure rejected the request; review the themes or language, then retry.',
            default => 'An administrator can retry this job after fixing the cause.',
        };
    }

    /** Flags text that looks like a quotation attributed to someone (shown to admins; the text is not changed). */
    public static function looksLikeQuotation(string $text): bool
    {
        return (bool)preg_match('/["“”«»][^"“”«»]{8,}["“”«»]\s*[—–-]\s*\p{Lu}|\b(once said|famously said|in the words of)\b/iu', $text);
    }

    private function beat(array $fields): void
    {
        $this->fs->update(self::HEARTBEAT, function (array &$d) use ($fields) {
            $d = array_merge($d, $fields);
        }, [], true);
    }
}
