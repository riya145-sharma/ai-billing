<?php
declare(strict_types=1);

/**
 * Daily motivation automation tests. Included by tests/run.php (shares its
 * harness: $fs, $users, $tmp, check(), section(), race(), freezeIst()).
 * Fake Azure transports only — no network, no charges.
 */

use App\Core\Clock;
use App\Core\Ids;
use App\Repositories\AutomationConfigRepository;
use App\Repositories\AutomationJobRepository;
use App\Repositories\AzureConfigRepository;
use App\Services\AutomationRunner;
use App\Services\AzureClient;
use App\Services\ContentGenerator;
use App\Services\DailyAllowance;
use App\Services\DeploymentThrottle;
use App\Services\GeneratorException;
use App\Services\SpeechGenerator;

putenv('TEST_CONTENT_KEY=content-test-key');
putenv('TEST_SPEECH_KEY=speech-test-key');
$azCfg = new AzureConfigRepository($fs);
$autoChatCfg = $azCfg->defaultFor('chat');
$autoSpeechCfg = $azCfg->update((int)$azCfg->defaultFor('speech')['id'], ['rpm_limit' => 3]);

$autoCalls = [];
$chatQueue = [];
$speechQueue = [];
$slept = [];
$autoTransport = function ($method, $url, $headers, $body) use (&$autoCalls, &$chatQueue, &$speechQueue) {
    $kind = str_contains($url, 'audio/speech') ? 'speech' : 'chat';
    $autoCalls[] = ['kind' => $kind, 'at' => Clock::now()->getTimestamp(), 'body' => json_decode((string)$body, true)];
    $next = $kind === 'speech' ? array_shift($speechQueue) : array_shift($chatQueue);
    return ($next ?? automationFakeResponse($url, (string)$body))
        + ['status' => 0, 'headers' => [], 'body' => '', 'error' => null, 'errno' => 0, 'sent' => true];
};
$advance = function (int $s) use (&$slept) {
    $slept[] = $s;
    Clock::freeze(Clock::now()->modify("+{$s} seconds"));
};
$runner = fn() => new AutomationRunner($fs, new AzureClient($fs, $autoTransport), $advance);
$calls = function (string $kind) use (&$autoCalls): int {
    return count(array_filter($autoCalls, fn($c) => $c['kind'] === $kind));
};
$autoConfig = new AutomationConfigRepository($fs);
$autoJobs = new AutomationJobRepository($fs);
$allow = new DailyAllowance($fs);
$jobOf = fn(string $date, array $u, string $time) => $autoJobs->find(AutomationJobRepository::jobId($date, (int)$u['id'], $time));
$timeout = ['status' => 0, 'error' => 'Operation timed out', 'errno' => 28, 'sent' => true];
$http = fn(int $status, array $headers = []) => ['status' => $status, 'headers' => $headers,
    'body' => json_encode(['error' => ['code' => (string)$status, 'message' => "Fake HTTP {$status}"]])];

section('Automation: configuration');
$cfg0 = $autoConfig->get();
check('defaults: 07:00, 13:00, 19:00 IST, English, nothing enabled', $cfg0['times'] === ['07:00', '13:00', '19:00']
    && $cfg0['language'] === 'English' && $cfg0['users'] === [] && count($cfg0['themes']) >= 3);
check('bad time rejected', throws(fn() => $autoConfig->saveSettings(['7am'], 'English', 'nova', $cfg0['themes'], 1), InvalidArgumentException::class));
check('more than 3 times rejected', throws(fn() => $autoConfig->saveSettings(['06:00', '07:00', '08:00', '09:00'], 'English', 'nova', $cfg0['themes'], 1), InvalidArgumentException::class));
check('fewer themes than runs rejected', throws(fn() => $autoConfig->saveSettings(['06:00', '07:00'], 'English', 'nova', ['Only one'], 1), InvalidArgumentException::class));
check('unknown voice rejected', throws(fn() => $autoConfig->saveSettings(['07:00'], 'English', 'robot', $cfg0['themes'], 1), InvalidArgumentException::class));
$saved = $autoConfig->saveSettings(['19:00', '07:00', '13:00', ''], 'Hindi', 'onyx', $cfg0['themes'], 1);
check('times normalised and sorted; language/voice configurable', $saved['times'] === ['07:00', '13:00', '19:00'] && $saved['language'] === 'Hindi' && $saved['voice'] === 'onyx');
$autoConfig->saveSettings(AutomationConfigRepository::DEFAULT_TIMES, 'English', 'nova', $cfg0['themes'], 1);
freezeIst('2026-11-02 08:00:00');
$r = $runner()->run(60);
check('nothing enabled → no jobs and no Azure calls', $autoCalls === [] && $autoJobs->forDay('2026-11-02') === [] && $r['planned'] === 0);
check('heartbeat recorded after a run', ($runner()->heartbeat()['last_status'] ?? '') === 'ok' && ($runner()->heartbeat()['timezone'] ?? '') === 'Asia/Kolkata');

section('Automation: schedule, catch-up and duplicate prevention');
$ua = $users->create('Auto A', 'auto-a@example.com', 'auto-user-password-1');
$autoConfig->setEnabledUsers([(int)$ua['id']], 1);
freezeIst('2026-11-03 06:59:00');
$runner()->run(60);
$day = $autoJobs->forDay('2026-11-03');
check('3 jobs planned at 07:00/13:00/19:00, none due yet, no Azure calls', count($day) === 3 && $autoCalls === []
    && array_column($day, 'status') === ['scheduled', 'scheduled', 'scheduled'] && array_column($day, 'time') === ['07:00', '13:00', '19:00']);
check('job due times are IST (07:00 IST = 01:30 UTC)', $jobOf('2026-11-03', $ua, '07:00')['due_at'] === '2026-11-03T01:30:00Z');
check('each run gets a different theme', count(array_unique(array_column($day, 'theme'))) === 3);
freezeIst('2026-11-03 07:02:00');
$runner()->run(240);
$j7 = $jobOf('2026-11-03', $ua, '07:00');
check('07:00 job completed: 1 text + 1 speech call', $j7['status'] === 'completed' && $calls('chat') === 1 && $calls('speech') === 1);
check('speech input is exactly the saved text', $autoCalls[1]['body']['input'] === $j7['content']['text'] && $j7['content']['text'] !== '');
check('voice from settings, MP3 saved privately', $autoCalls[1]['body']['voice'] === 'nova'
    && file_get_contents($fs->path(AutomationJobRepository::audioRel((int)$ua['id'], $j7['id']))) === 'ID3' . str_repeat("\0", 64));
check('text is in the 100–150 word target range', $j7['content']['word_count'] >= 100 && $j7['content']['word_count'] <= 150, (string)$j7['content']['word_count']);
$sys = $autoCalls[0]['body']['messages'][0]['content'];
check('prompt: language, 100–150 words, no attributed quotations, theme', str_contains($sys, 'English') && str_contains($sys, '100 to 150 words')
    && str_contains($sys, 'never attribute words') && str_contains($autoCalls[0]['body']['messages'][1]['content'], $j7['theme']));
check('13:00 and 19:00 not run before they are due', $jobOf('2026-11-03', $ua, '13:00')['status'] === 'scheduled');
$runner()->run(240);
freezeIst('2026-11-03 07:04:00');
$runner()->run(240);
check('repeated runs make no duplicate Azure calls', count($autoCalls) === 2);
freezeIst('2026-11-03 19:30:00');
$runner()->run(240);
$day = $autoJobs->forDay('2026-11-03');
check('missed 13:00 and 19:00 caught up the same day', array_column($day, 'status') === ['completed', 'completed', 'completed']
    && $calls('chat') === 3 && $calls('speech') === 3);
$chatCalls = array_values(array_filter($autoCalls, fn($c) => $c['kind'] === 'chat'));
check('later prompts ask to differ from earlier messages', str_contains(end($chatCalls)['body']['messages'][1]['content'], 'clearly different'));
check('three different texts', count(array_unique(array_map(fn($j) => $j['content']['text'], $day))) === 3);
$st = $allow->status((int)$ua['id'], '2026-11-03');
check('allowance: 3 content + 3 speech used, 0 remaining', $st['content']['used'] === 3 && $st['speech']['used'] === 3
    && $st['content']['remaining'] === 0 && $st['speech']['remaining'] === 0 && $st['content']['auto_used'] === 3);
$n = count($autoCalls);
$manual = new ContentGenerator($fs, new AzureClient($fs, $autoTransport));
$e = null;
try { $manual->generate((int)$ua['id'], 'One more please', 'General content', 'Friendly', Ids::uuid()); } catch (GeneratorException $ex) { $e = $ex; }
check('manual content refused at the shared limit, without an Azure call', $e?->reason === 'daily_limit' && count($autoCalls) === $n
    && str_contains($e->getMessage(), 'midnight IST'), (string)$e?->getMessage());
freezeIst('2026-11-04 00:00:30');
check('allowance resets at IST midnight; history kept', $allow->status((int)$ua['id'])['content']['remaining'] === 3
    && $allow->status((int)$ua['id'], '2026-11-03')['content']['used'] === 3);
check('23:59:59 IST is still the old day', (function () { freezeIst('2026-11-03 23:59:59'); return Clock::businessDate() === '2026-11-03'; })());

section('Automation: daily limit shared with manual requests');
$ub = $users->create('Auto B', 'auto-b@example.com', 'auto-user-password-2');
$autoConfig->setEnabledUsers([(int)$ub['id']], 1);
freezeIst('2026-11-13 06:00:00');
$manual = new ContentGenerator($fs, new AzureClient($fs, $autoTransport));
$manual->generate((int)$ub['id'], 'Morning brief', 'General content', 'Friendly', Ids::uuid());
$manual->generate((int)$ub['id'], 'Second brief', 'General content', 'Friendly', Ids::uuid());
$chatQueue = [$http(401)];
$failedManual = $manual->generate((int)$ub['id'], 'Fails', 'General content', 'Friendly', Ids::uuid());
$st = $allow->status((int)$ub['id']);
check('failed manual request does not consume the allowance', $failedManual['status'] === 'failed'
    && $st['content']['used'] === 2 && $st['content']['failed'] === 1 && $st['content']['remaining'] === 1);
freezeIst('2026-11-13 13:05:00');
$n = count($autoCalls);
$runner()->run(240);
$j7 = $jobOf('2026-11-13', $ub, '07:00');
$j13 = $jobOf('2026-11-13', $ub, '13:00');
check('only the remaining allowance is generated (1 pair)', $j7['status'] === 'completed' && count($autoCalls) === $n + 2);
check('next pair blocked, nothing sent to Azure', $j13['status'] === 'blocked' && $j13['content']['attempts'] === 0);
check('blocked message explains manual use consumed the allowance', str_contains((string)$j13['message'], 'Manual requests consumed')
    && str_contains((string)$j13['message'], '2 used manually'), (string)$j13['message']);
$st = $allow->status((int)$ub['id']);
check('speech allowance still has 2 left for manual use', $st['speech']['remaining'] === 2 && $st['content']['remaining'] === 0);
$manualSpeech = new SpeechGenerator($fs, new AzureClient($fs, $autoTransport));
check('manual speech still allowed within its own limit', $manualSpeech->generate((int)$ub['id'], 'Hello there', 'alloy', Ids::uuid())['status'] === 'success');
check('users without automation keep unlimited manual generation', $allow->status((int)$ua['id'])['limit'] === null);
$autoConfig->setEnabledUsers([], 1);
check('limit applies only while automation is enabled', $allow->status((int)$ub['id'])['limit'] === null);
$autoConfig->setEnabledUsers([(int)$ub['id']], 1);

section('Automation: retries, Retry-After and partial failures');
$uc = $users->create('Auto C', 'auto-c@example.com', 'auto-user-password-3');
$autoConfig->setEnabledUsers([(int)$uc['id']], 1);
freezeIst('2026-11-23 07:01:00');
$n = count($autoCalls);
$chatQueue = [$http(429, ['retry-after' => '120'])];
$runner()->run(30);
$j = $jobOf('2026-11-23', $uc, '07:00');
$wait = Clock::parseIso($j['content']['next_attempt_at'])->getTimestamp() - Clock::now()->getTimestamp();
check('429 → waiting_retry, honouring Retry-After (120 s > 60 s backoff)', $j['status'] === 'waiting_retry' && $wait >= 119 && $wait <= 120, "wait {$wait}");
check('retry message is actionable', str_contains((string)$j['message'], 'Retrying automatically'), (string)$j['message']);
check('throttle cooldown set from Retry-After', (new DeploymentThrottle($fs))->waitTime($autoChatCfg) >= 119);
$st = $allow->status((int)$uc['id']);
check('failed attempt not counted as a success; capacity stays reserved', $st['content']['used'] === 0 && $st['content']['reserved'] === 1);
freezeIst('2026-11-23 07:04:00');
$speechQueue = [$http(503)];
$slept = [];
$runner()->run(240);
$j = $jobOf('2026-11-23', $uc, '07:00');
$speechBodies = array_values(array_filter(array_slice($autoCalls, $n), fn($c) => $c['kind'] === 'speech'));
check('transient speech failure retried in-run after backoff', $j['status'] === 'completed' && count($speechBodies) === 2 && in_array(60, $slept, true), json_encode($slept));
check('speech retry reused the saved text (no new text generation)', $speechBodies[0]['body']['input'] === $speechBodies[1]['body']['input']
    && $speechBodies[1]['body']['input'] === $j['content']['text'] && $calls('chat') - count(array_filter(array_slice($autoCalls, 0, $n), fn($c) => $c['kind'] === 'chat')) === 2);
check('failed attempts kept separately in job history', count(array_filter($j['attempts'], fn($a) => $a['outcome'] === 'failed')) === 2 && count($j['attempts']) === 4);
$st = $allow->status((int)$uc['id']);
check('successful counts are exact: 1 content, 1 speech', $st['content']['used'] === 1 && $st['speech']['used'] === 1 && $st['content']['failed'] === 0);

freezeIst('2026-11-23 13:01:00');
$speechQueue = [$http(400)];
$n = count($autoCalls);
$runner()->run(240);
$j = $jobOf('2026-11-23', $uc, '13:00');
check('permanent speech failure (400) is not retried', $j['status'] === 'failed' && $j['speech']['attempts'] === 1 && count($autoCalls) === $n + 2);
check('text kept after speech failure, with an actionable message', $j['content']['state'] === 'success' && str_contains((string)$j['message'], 'text is kept'));
$st = $allow->status((int)$uc['id']);
check('failed speech does not use the speech allowance', $st['speech']['used'] === 1 && $st['speech']['failed'] === 1 && $st['speech']['remaining'] === 2
    && $st['content']['used'] === 2);
$runner()->retry($j['id'], 1);
$n = count($autoCalls);
$runner()->run(240);
$j = $jobOf('2026-11-23', $uc, '13:00');
check('admin retry generates only the speech, from the saved text', $j['status'] === 'completed' && count($autoCalls) === $n + 1
    && end($autoCalls)['body']['input'] === $j['content']['text'] && $j['round'] === 2);
check('retry of a completed job is refused', throws(fn() => $runner()->retry($j['id'], 1), InvalidArgumentException::class));

freezeIst('2026-11-23 19:01:00');
$chatQueue = [['status' => 0, 'error' => 'Could not connect', 'errno' => 7, 'sent' => false]];
$runner()->run(240);
$j = $jobOf('2026-11-23', $uc, '19:00');
check('connection never made → confirmed transient failure, retried', $j['status'] === 'completed' && $j['content']['attempts'] === 2);

section('Automation: uncertain outcomes are never repeated automatically');
$ud = $users->create('Auto D', 'auto-d@example.com', 'auto-user-password-4');
$autoConfig->setEnabledUsers([(int)$ud['id']], 1);
freezeIst('2026-12-03 07:01:00');
$chatQueue = [$timeout];
$n = count($autoCalls);
$runner()->run(240);
$j = $jobOf('2026-12-03', $ud, '07:00');
check('timeout after sending → uncertain', $j['status'] === 'uncertain' && $j['content']['state'] === 'unknown' && count($autoCalls) === $n + 1);
freezeIst('2026-12-03 07:30:00');
$runner()->run(240);
check('uncertain request not repeated by later runs', count($autoCalls) === $n + 1);
$st = $allow->status((int)$ud['id']);
check('uncertain request holds content capacity; speech released', $st['content']['reserved'] === 1 && $st['content']['remaining'] === 2
    && $st['speech']['remaining'] === 3);
$runner()->retry($j['id'], 1);
$st = $allow->status((int)$ud['id']);
check('admin-confirmed retry releases the held capacity', $st['content']['reserved'] === 0 && $st['content']['remaining'] === 3);
$runner()->run(240);
$j = $jobOf('2026-12-03', $ud, '07:00');
check('retried job completes; counts exact', $j['status'] === 'completed' && $allow->status((int)$ud['id'])['content']['used'] === 1);
freezeIst('2026-12-03 13:01:00');
$speechQueue = [$timeout];
$runner()->run(240);
$j = $jobOf('2026-12-03', $ud, '13:00');
check('speech timeout → uncertain, text kept, speech capacity held', $j['status'] === 'uncertain' && $j['content']['state'] === 'success'
    && $allow->status((int)$ud['id'])['speech']['reserved'] === 1);
$speechQueue = [['status' => 200, 'body' => '{"error":"not audio"}']];
freezeIst('2026-12-03 19:01:00');
$runner()->run(240);
check('non-MP3 success body treated as uncertain (never saved)', $jobOf('2026-12-03', $ud, '19:00')['status'] === 'uncertain'
    && !is_file($fs->path(AutomationJobRepository::audioRel((int)$ud['id'], AutomationJobRepository::jobId('2026-12-03', (int)$ud['id'], '19:00')))));

section('Automation: recovery after an interrupted run');
$ue = $users->create('Auto E', 'auto-e@example.com', 'auto-user-password-5');
$autoConfig->setEnabledUsers([(int)$ue['id']], 1);
freezeIst('2026-12-13 06:00:00');
$runner()->run(60);
$id7 = AutomationJobRepository::jobId('2026-12-13', (int)$ue['id'], '07:00');
$id13 = AutomationJobRepository::jobId('2026-12-13', (int)$ue['id'], '13:00');
foreach ([$id7, $id13] as $jid) {
    $allow->reserve((int)$ue['id'], ['content', 'speech'], 'auto', 'auto:' . $jid . ':1', '2026-12-13');
}
// 07:00: died mid-way through the text request. 13:00: died after saving the text, before speech.
$autoJobs->change($id7, fn($j) => array_merge($j, ['reserved' => true, 'status' => 'running',
    'content' => array_merge($j['content'], ['state' => 'requesting', 'attempts' => 1, 'claimed_by' => 'dead00run000'])]));
$autoJobs->change($id13, fn($j) => array_merge($j, ['reserved' => true, 'status' => 'queued', 'language' => 'English', 'voice' => 'nova',
    'content' => array_merge($j['content'], ['state' => 'success', 'attempts' => 1, 'text' => 'Saved before the crash.'])]));
$allow->settle((int)$ue['id'], 'content', 'auto:' . $id13 . ':1', 'success', '2026-12-13');
freezeIst('2026-12-13 13:10:00');
$n = count($autoCalls);
$runner()->run(240);
$j7 = $autoJobs->find($id7);
$j13 = $autoJobs->find($id13);
check('in-flight request from a dead run → uncertain, not repeated', $j7['status'] === 'uncertain' && $j7['content']['attempts'] === 1);
check('saved text resumed: only speech requested, with that text', $j13['status'] === 'completed' && count($autoCalls) === $n + 1
    && end($autoCalls)['body']['input'] === 'Saved before the crash.');

section('Automation: speech rate limit (3 per minute)');
$rl = [];
foreach (['F', 'G', 'H', 'I'] as $k) {
    $rl[] = $users->create("Auto {$k}", "auto-" . strtolower($k) . "@example.com", 'auto-user-password-' . $k . 'x');
}
$autoConfig->setEnabledUsers(array_map(fn($u) => (int)$u['id'], $rl), 1);
freezeIst('2026-12-23 07:01:00');
$n = count($autoCalls);
$slept = [];
$runner()->run(240);
$speechTimes = array_column(array_filter(array_slice($autoCalls, $n), fn($c) => $c['kind'] === 'speech'), 'at');
$maxInWindow = 0;
foreach ($speechTimes as $t) {
    $maxInWindow = max($maxInWindow, count(array_filter($speechTimes, fn($x) => $x > $t - 60 && $x <= $t)));
}
check('4 jobs: all completed', count(array_filter($autoJobs->forDay('2026-12-23'), fn($j) => $j['status'] === 'completed')) === 4);
check('never more than 3 speech requests in any 60 s window', count($speechTimes) === 4 && $maxInWindow === 3, json_encode($speechTimes));
check('runner waited for the rate-limit window', $slept !== [] && max($slept) >= 55, json_encode($slept));
$throttle = new DeploymentThrottle($fs);
freezeIst('2026-12-23 09:00:00');
$throttle->acquire($autoSpeechCfg);
$throttle->acquire($autoSpeechCfg);
$throttle->acquire($autoSpeechCfg);
$n = count($autoCalls);
$e = null;
try { (new SpeechGenerator($fs, new AzureClient($fs, $autoTransport)))->generate((int)$ub['id'], 'Busy minute', 'alloy', Ids::uuid()); } catch (GeneratorException $ex) { $e = $ex; }
check('manual speech shares the 3/min limit: refused without an Azure call', $e?->reason === 'rate' && count($autoCalls) === $n
    && str_contains($e->getMessage(), '3 requests per minute'), (string)$e?->getMessage());
check('refused manual request does not hold an allowance place', $allow->status((int)$ub['id'], '2026-12-23')['speech']['reserved'] === 0);

section('Automation: IST midnight and no backlog for earlier days');
$ug = $users->create('Auto Midnight', 'auto-m@example.com', 'auto-user-password-m');
$autoConfig->setEnabledUsers([(int)$ug['id']], 1);
$autoConfig->saveSettings(['07:00', '13:00', '23:55'], 'English', 'nova', $cfg0['themes'], 1);
freezeIst('2027-01-05 23:56:00');
$speechQueue = [automationFakeResponse('audio/speech', ''), automationFakeResponse('audio/speech', ''), $http(503)];
$runner()->run(20);
$late = $jobOf('2027-01-05', $ug, '23:55');
check('late job waiting to retry speech at 23:56', $late['status'] === 'waiting_retry' && $late['content']['state'] === 'success');
freezeIst('2027-01-06 00:03:00');
$n = count($autoCalls);
$runner()->run(240);
$late = $jobOf('2027-01-05', $ug, '23:55');
check('after midnight the previous day is closed, not caught up', $late['status'] === 'expired' && count($autoCalls) === $n
    && str_contains((string)$late['message'], 'text is kept'));
$old = $allow->status((int)$ug['id'], '2027-01-05');
check('previous day: held speech capacity released, history kept', $old['speech']['used'] === 2 && $old['speech']['reserved'] === 0 && $old['content']['used'] === 3);
$new = $allow->status((int)$ug['id'], '2027-01-06');
check('new IST day: full allowance, 3 jobs planned, none due yet', $new['content']['remaining'] === 3 && $new['speech']['remaining'] === 3
    && array_column($autoJobs->forDay('2027-01-06'), 'status') === ['scheduled', 'scheduled', 'scheduled']);
$autoConfig->saveSettings(['08:30'], 'English', 'nova', $cfg0['themes'], 1);
$runner()->run(60);
check('schedule change: unstarted jobs replaced by the new time', array_column($autoJobs->forDay('2027-01-06'), 'time') === ['08:30']);
$autoConfig->setEnabledUsers([], 1);
$runner()->run(60);
check('disabling automation removes unstarted jobs', $autoJobs->forDay('2027-01-06') === []);
$autoConfig->saveSettings(AutomationConfigRepository::DEFAULT_TIMES, 'English', 'nova', $cfg0['themes'], 1);

section('Automation: concurrent cron runs and concurrent requests');
$uh = $users->create('Auto Race', 'auto-race@example.com', 'auto-user-password-r');
$autoConfig->setEnabledUsers([(int)$uh['id']], 1);
$callLog = $tmp . '/autorun-calls.log';
$results = race('autorun', $tmp, 5, ['2027-02-01 19:05:00', $callLog]);
$logged = array_count_values(array_filter(explode("\n", (string)@file_get_contents($callLog))));
check('5 overlapping cron runs → each job sent exactly once ' . json_encode($results) . ' ' . json_encode($logged),
    ($logged['chat'] ?? 0) === 3 && ($logged['speech'] ?? 0) === 3 && ($results['RAN'] ?? 0) >= 1 && count($results) <= 2);
check('all 3 jobs completed after the race', array_column($autoJobs->forDay('2027-02-01'), 'status') === ['completed', 'completed', 'completed']);
$results = race('allowance', $tmp, 8, [(string)$uh['id'], '2027-02-10 10:00:00']);
check('8 concurrent manual reservations → exactly 3 allowed ' . json_encode($results), ($results['WON'] ?? 0) === 3 && ($results['LIMIT'] ?? 0) === 5);
freezeIst('2027-02-10 10:00:00');
check('pending reservations hold capacity', $allow->status((int)$uh['id'])['content']['reserved'] === 3);
freezeIst('2027-02-10 10:30:00');
$allow->reconcileStale('2027-02-10', 600);
$st = $allow->status((int)$uh['id']);
check('stale manual reservations become uncertain but keep holding capacity', $st['content']['reserved'] === 3 && $st['content']['remaining'] === 0);

section('Automation: privacy and helpers');
check('job ids are strict (forged ids rejected)', AutomationJobRepository::parseId('job_20261103_5_0700') === ['date' => '2026-11-03', 'user_id' => 5, 'time' => '07:00']
    && AutomationJobRepository::parseId('job_20261103_5_0700/../x') === null && AutomationJobRepository::parseId('job_20261340_5_0700') === null);
freezeIst('2026-11-13 12:00:00');
$mineA = $autoJobs->recentForUser((int)$ua['id'], 14);
$mineB = $autoJobs->recentForUser((int)$ub['id'], 14);
check('users only see their own jobs', count($mineA) === 3 && count($mineB) === 3
    && array_unique(array_column($mineA, 'user_id')) === [(int)$ua['id']] && array_unique(array_column($mineB, 'user_id')) === [(int)$ub['id']]);
check('audio path is scoped to the owner', AutomationJobRepository::audioRel(7, 'job_20261103_7_0700') === 'automation/audio/7/job_20261103_7_0700.mp3');
check('Retry-After parsed (seconds, ms)', AzureClient::retryAfter(['retry-after' => '20']) === 20 && AzureClient::retryAfter(['retry-after-ms' => '1500']) === 2
    && AzureClient::retryAfter([]) === null);
check('transient vs permanent classification', AutomationRunner::isTransient(['outcome' => 'failed', 'http_status' => 503, 'error_code' => 'x'])
    && !AutomationRunner::isTransient(['outcome' => 'failed', 'http_status' => 400, 'error_code' => 'x'])
    && !AutomationRunner::isTransient(['outcome' => 'unknown', 'http_status' => 504, 'error_code' => 'x']));
check('attributed quotations are flagged', AutomationRunner::looksLikeQuotation('"Never give up on your dreams" — Someone Famous')
    && !AutomationRunner::looksLikeQuotation('You can do this, one step at a time.'));
$secretSeen = false;
foreach ($autoCalls as $c) {
    $secretSeen = $secretSeen || str_contains(json_encode($c['body']), 'test-key');
}
$jobsJson = (string)@file_get_contents($fs->path(AutomationJobRepository::file('2026-11-23')));
check('API keys never appear in request bodies or job records', !$secretSeen && !str_contains($jobsJson, 'test-key') && !str_contains($jobsJson, 'api-key'));

$autoConfig->setEnabledUsers([], 1);
Clock::freeze(null);
putenv('TEST_CONTENT_KEY');
putenv('TEST_SPEECH_KEY');
