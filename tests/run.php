<?php
declare(strict_types=1);

/**
 * Self-contained test suite (no PHPUnit needed). Uses a temporary storage
 * folder, never your real storage/.
 *   php tests/run.php
 * Concurrency tests spawn parallel PHP processes that race on the same files.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Clock;
use App\Core\Ids;
use App\Core\Money;
use App\Core\Settings;
use App\Repositories\AzureConfigRepository;
use App\Repositories\BudgetRepository;
use App\Repositories\ImageGenerationRepository;
use App\Repositories\PricingRepository;
use App\Repositories\SlotDefinitionRepository;
use App\Repositories\UsageLogRepository;
use App\Repositories\UserRepository;
use App\Services\ImageStore;
use App\Services\LoginService;
use App\Services\AllowanceException;
use App\Services\AutomationRunner;
use App\Services\AzureClient;
use App\Services\DailyAllowance;
use App\Services\GeneratorException;
use App\Services\ImageGenerator;
use App\Services\ImageModels;
use App\Services\RateLimiter;
use App\Core\Url;
use App\Services\QuotaException;
use App\Services\QuotaReconciler;
use App\Services\QuotaService;
use App\Storage\Collection;
use App\Storage\CorruptDataException;
use App\Storage\DuplicateException;
use App\Storage\FileStore;
use App\Storage\Installer;
use App\Storage\StorageException;

function useTmp(string $tmp): FileStore
{
    $fs = new FileStore($tmp . '/storage/data', $tmp . '/storage/locks');
    App::useStore($fs);
    return $fs;
}

function freezeIst(string $local): void
{
    Clock::freeze(new DateTimeImmutable($local, new DateTimeZone('Asia/Kolkata')));
}

/** Fake Azure answer for automation tests: ~120-word chat text, or a tiny MP3. */
function automationFakeResponse(string $url, string $body): array
{
    $base = ['status' => 200, 'headers' => [], 'body' => '', 'error' => null, 'errno' => 0, 'sent' => true];
    if (str_contains($url, 'audio/speech')) {
        return ['headers' => ['content-type' => 'audio/mpeg'], 'body' => 'ID3' . str_repeat("\0", 64)] + $base;
    }
    $text = 'Message ' . substr(md5($body), 0, 8) . '. ' . trim(str_repeat('You are growing stronger with every honest step you take today. ', 10));
    return ['body' => json_encode(['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 160, 'total_tokens' => 310]])] + $base;
}

// ======================================================= child processes for race tests
if (($argv[1] ?? '') === '--child') {
    [, , $task, $tmp] = $argv;
    $fs = useTmp($tmp);
    try {
        switch ($task) {
            case 'counter':
                for ($i = 0; $i < 100; $i++) {
                    $fs->update('counter.json', function (array &$d) { $d['n'] = ($d['n'] ?? 0) + 1; });
                }
                echo "DONE";
                break;
            case 'append':
                for ($i = 0; $i < 50; $i++) {
                    $fs->append('log.jsonl', ['pid' => getmypid(), 'i' => $i, 'pad' => str_repeat('x', 200)]);
                }
                echo "DONE";
                break;
            case 'email':
                (new Collection($fs, 'race-users.json', ['email' => fn($u) => $u['email'] ?? null]))->insert(['email' => 'same@example.com']);
                echo "WON";
                break;
            case 'reserve':
                freezeIst($argv[5]);
                (new QuotaService($fs))->reserve((int)$argv[4], Ids::uuid() . 'x');
                echo "WON";
                break;
            case 'autorun':
                // Overlapping cron runs: Azure calls are appended to a shared log file.
                freezeIst($argv[4]);
                putenv('TEST_CONTENT_KEY=content-test-key');
                putenv('TEST_SPEECH_KEY=speech-test-key');
                $log = $argv[5];
                $transport = function ($method, $url, $headers, $body) use ($log) {
                    file_put_contents($log, (str_contains($url, 'audio/speech') ? 'speech' : 'chat') . "\n", FILE_APPEND | LOCK_EX);
                    usleep(200_000);
                    return automationFakeResponse($url, (string)$body);
                };
                $advance = fn(int $s) => Clock::freeze(Clock::now()->modify("+{$s} seconds"));
                $res = (new AutomationRunner($fs, new AzureClient($fs, $transport), $advance))->run(600);
                echo !empty($res['skipped']) ? 'SKIP' : 'RAN';
                break;
            case 'allowance':
                freezeIst($argv[5]);
                (new DailyAllowance($fs))->reserve((int)$argv[4], ['content'], 'manual', 'manual:' . Ids::uuid());
                echo "WON";
                break;
        }
    } catch (AllowanceException) {
        echo "LIMIT";
    } catch (DuplicateException) {
        echo "DUP";
    } catch (QuotaException $e) {
        echo "Q:" . $e->reason;
    } catch (\Throwable $e) {
        echo "ERR:" . $e->getMessage();
    }
    exit;
}

// ======================================================= harness
$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/aibill-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
register_shutdown_function(function () use ($tmp) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
});

$pass = 0;
$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  PASS ' : '  FAIL ') . $name . ($cond || $detail === '' ? '' : "  → {$detail}") . "\n";
}
function section(string $s): void
{
    echo "\n{$s}\n";
}
function throws(callable $fn, string $class = \Throwable::class): bool
{
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return $e instanceof $class;
    }
}
function quotaReason(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (QuotaException $e) {
        return $e->reason;
    }
}
function race(string $task, string $tmp, int $n, array $extra = []): array
{
    $procs = [];
    $pipes = [];
    for ($i = 0; $i < $n; $i++) {
        $cmd = array_merge([PHP_BINARY, __FILE__, '--child', $task, $tmp], $extra);
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $p) {
        $out[] = trim(stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]));
        proc_close($p);
    }
    return array_count_values($out);
}

(new Installer($tmp))->run();
$fs = useTmp($tmp);
$users = new UserRepository($fs);
$u1 = $users->create('Alice', 'alice@example.com', 'correct-horse-battery', 'user');
$u2 = $users->create('Bob', 'bob@example.com', 'correct-horse-battery', 'user');

// ======================================================= storage engine
section('Storage engine');
foreach (['../x.json', 'a/../b.json', '/etc/passwd', 'a//b.json', 'a\\..\\b.json', '.hidden', 'dir/'] as $bad) {
    check("rejects unsafe path {$bad}", throws(fn() => $fs->path($bad), StorageException::class));
}
$counts = race('counter', $tmp, 8);
check('8 processes × 100 locked increments = 800 (no lost updates) ' . json_encode($counts), ($fs->read('counter.json')['n'] ?? 0) === 800);
$counts = race('append', $tmp, 8);
$lines = $fs->readLines('log.jsonl');
check('8 processes × 50 appends = 400 intact JSON lines', count($lines) === 400);
file_put_contents($tmp . '/storage/data/broken.json', '{"items": {');
check('corrupt JSON raises CorruptDataException (never silently reset)', throws(fn() => $fs->update('broken.json', fn(&$d) => null), CorruptDataException::class));
check('corrupt file left untouched for recovery', file_get_contents($tmp . '/storage/data/broken.json') === '{"items": {');
check('re-entrant lock: nested update on same file works', $fs->update('nest.json', function (array &$d) use ($fs) {
    $d['outer'] = true;
    return $fs->withLock('nest.json', true, fn() => 'inner-ok');
}) === 'inner-ok');
check('no temp files left behind', glob($tmp . '/storage/data/.~*') === []);
$counts = race('email', $tmp, 10);
check('unique email under 10-way race: exactly 1 insert ' . json_encode($counts), ($counts['WON'] ?? 0) === 1 && ($counts['DUP'] ?? 0) === 9);
check('duplicate email (case-insensitive) rejected', throws(fn() => $users->create('A2', 'ALICE@example.com', 'correct-horse-battery'), DuplicateException::class));
check('short password rejected', throws(fn() => $users->create('C', 'c@example.com', 'short'), InvalidArgumentException::class));
$raw = $users->findByEmailWithSecret('alice@example.com');
check('password stored hashed, verifies', str_starts_with($raw['password_hash'], '$') && $users->verifyPassword($raw, 'correct-horse-battery'));
check('wrong password rejected', !$users->verifyPassword($raw, 'wrong-password-123'));
check('public view hides hash', !isset($users->find($u1['id'])['password_hash']));

// ======================================================= quota
section('Quota: race & idempotency');
$q = new QuotaService($fs);
$counts = race('reserve', $tmp, 20, [(string)$u1['id'], '2026-10-20 07:00:00']);
check('20 parallel reservations for one slot → exactly 1 wins ' . json_encode($counts), ($counts['WON'] ?? 0) === 1 && ($counts['Q:generation_in_progress'] ?? 0) === 19);
$day = $fs->read(Ids::dayFile('quota', '2026-10-20'));
check('exactly one reservation stored', count($day['reservations']) === 1);

freezeIst('2026-10-21 07:00:00');
$key = Ids::uuid();
$r1 = $q->reserve($u1['id'], $key);
$r2 = $q->reserve($u1['id'], $key);
check('same idempotency key → same reservation (replayed)', $r1['reservation']['id'] === $r2['reservation']['id'] && $r2['replayed'] === true);
check('second attempt with new key while in progress → blocked', quotaReason(fn() => $q->reserve($u1['id'], Ids::uuid())) === 'generation_in_progress');
check('another user is unaffected (per-user scope)', $q->reserve($u2['id'], Ids::uuid())['replayed'] === false);

section('Quota: commit / release / slot rules');
$q->commit($r1['reservation']['id'], 1, null);
try {
    $q->reserve($u1['id'], Ids::uuid());
    $msg = '';
} catch (QuotaException $e) {
    $msg = $e->getMessage();
}
check('used slot → exact spec message', $msg === 'Image generation for this time period has already been used.', $msg);
check('commit is idempotent', $q->commit($r1['reservation']['id'], 1)['status'] === 'committed'
    && $q->status($u1['id'])['images_today'] === 1);

freezeIst('2026-10-22 08:00:00');
$r = $q->reserve($u1['id'], Ids::uuid());
$q->release($r['reservation']['id'], 'azure returned 500');
check('confirmed failure releases the slot; retry in same window allowed', $q->reserve($u1['id'], Ids::uuid())['replayed'] === false);

freezeIst('2026-10-23 03:00:00');
try {
    $q->reserve($u1['id'], Ids::uuid());
    $e = null;
} catch (QuotaException $e) {
}
check('00:00–05:59 IST → disabled, tells next slot time', $e?->reason === 'outside_window' && str_contains($e->getMessage(), '6:00 AM'), (string)$e?->getMessage());

freezeIst('2026-10-23 13:00:00');
$st = $q->status($u1['id']);
check('unused morning shows expired at 1 PM', $st['slots'][0]['state'] === 'expired');
check('afternoon available, evening upcoming', $st['slots'][1]['state'] === 'available' && $st['slots'][2]['state'] === 'upcoming');
check('remaining = 2 (expired morning not carried forward)', $st['remaining_today'] === 2, (string)$st['remaining_today']);
$r = $q->reserve($u1['id'], Ids::uuid());
check('reservation goes to the current (afternoon) slot', $r['reservation']['slot_code'] === 'afternoon');
$q->commit($r['reservation']['id'], 1);
freezeIst('2026-10-23 17:59:59');
check('17:59:59 still afternoon → slot used', quotaReason(fn() => $q->reserve($u1['id'], Ids::uuid())) === 'slot_used');
freezeIst('2026-10-23 18:00:00');
check('18:00:00 evening opens', $q->reserve($u1['id'], Ids::uuid())['reservation']['slot_code'] === 'evening');

section('Quota: daily limit & actual image counting');
freezeIst('2026-10-24 06:00:00');
$r = $q->reserve($u1['id'], Ids::uuid());
$q->commit($r['reservation']['id'], 1);
freezeIst('2026-10-24 12:00:00');
$r = $q->reserve($u1['id'], Ids::uuid());
$q->commit($r['reservation']['id'], 1);
freezeIst('2026-10-24 18:30:00');
$r = $q->reserve($u1['id'], Ids::uuid());
$q->commit($r['reservation']['id'], 1);
freezeIst('2026-10-24 23:59:59');
$st = $q->status($u1['id']);
check('3 used → limit reached, 0 remaining', $st['limit_reached'] && $st['remaining_today'] === 0 && $st['images_today'] === 3);
check('exact spec daily-limit message', $st['message'] === 'You have reached your daily limit of 3 image generations. Your quota will reset tomorrow.');
freezeIst('2026-10-25 06:00:00');
check('resets at midnight IST: new day has full quota', $q->status($u1['id'])['remaining_today'] === 3);

freezeIst('2026-10-26 07:00:00');
$r = $q->reserve($u1['id'], Ids::uuid());
$q->commit($r['reservation']['id'], 2);   // model returned 2 images despite n=1
freezeIst('2026-10-26 13:00:00');
$r = $q->reserve($u1['id'], Ids::uuid());
$q->commit($r['reservation']['id'], 1);
freezeIst('2026-10-26 19:00:00');
check('extra images count: 2+1 = 3 → evening blocked by daily limit', quotaReason(fn() => $q->reserve($u1['id'], Ids::uuid())) === 'daily_limit_reached');
freezeIst('2026-10-27 07:00:00');
$r = $q->reserve($u1['id'], Ids::uuid());
check('commit with 0 images = failure → slot released', $q->commit($r['reservation']['id'], 0)['status'] === 'released');

section('Quota: lost responses & reconciliation');
freezeIst('2026-10-28 11:58:00');
$r = $q->reserve($u1['id'], Ids::uuid());
$q->markReconciling($r['reservation']['id'], 'timeout');
freezeIst('2026-10-28 12:30:00');
check('unverified morning attempt blocks afternoon until reconciled', quotaReason(fn() => $q->reserve($u1['id'], Ids::uuid())) === 'pending_reconciliation');

$gens = new ImageGenerationRepository($fs);
freezeIst('2026-10-29 07:00:00');
$rNoGen = $q->reserve($u1['id'], Ids::uuid())['reservation'];          // crashed before API call
$rOk = $q->reserve($u2['id'], Ids::uuid())['reservation'];             // succeeded, response lost
$g = $gens->create(['user_id' => $u2['id'], 'reservation_id' => $rOk['id'], 'prompt' => 'a cat']);
$gens->update($g['id'], ['status' => 'succeeded', 'image_count' => 1]);
freezeIst('2026-10-29 07:10:00');
$stats = $q->reconcile(new QuotaReconciler($fs));
check('reconcile: no generation record → released', $q->getReservation($rNoGen['id'])['status'] === 'released');
check('reconcile: succeeded generation → committed (slot used)', $q->getReservation($rOk['id'])['status'] === 'committed'
    && $q->status($u2['id'])['slots'][0]['state'] === 'used');

$u3 = $users->create('Cara', 'cara@example.com', 'correct-horse-battery');
freezeIst('2026-10-29 08:00:00');
$rUnk = $q->reserve($u3['id'], Ids::uuid())['reservation'];
$g = $gens->create(['user_id' => $u3['id'], 'reservation_id' => $rUnk['id'], 'prompt' => 'a dog']); // stays pending
freezeIst('2026-10-29 08:10:00');
$q->reconcile(new QuotaReconciler($fs));
check('reconcile: unknown & young → reconciling (blocks)', $q->getReservation($rUnk['id'])['status'] === 'reconciling');
freezeIst('2026-10-29 08:45:00');
$q->reconcile(new QuotaReconciler($fs));
$res = $q->getReservation($rUnk['id']);
check('reconcile: unknown after 30 min → counted as used, flagged unverified', $res['status'] === 'committed' && $res['unverified'] === true);
$q->adminReleaseSlot('2026-10-29', 'u:' . $u3['id'], 'morning', 1, 'confirmed failed in Azure');
check('admin can give an unverified slot back', $q->status($u3['id'])['slots'][0]['state'] === 'available');

section('Quota: scope & account rules');
Settings::set('quota.scope', 'org');
freezeIst('2026-10-30 07:00:00');
$r = $q->reserve($u1['id'], Ids::uuid());
$q->commit($r['reservation']['id'], 1);
check('org scope: slot shared — Bob blocked after Alice used morning', quotaReason(fn() => $q->reserve($u2['id'], Ids::uuid())) === 'slot_used');
Settings::set('quota.scope', 'user');
$users->update($u2['id'], ['daily_image_limit' => 0]);
check('per-user limit 0 → no access', quotaReason(fn() => $q->reserve($u2['id'], Ids::uuid())) === 'no_image_access');
$users->update($u2['id'], ['daily_image_limit' => null, 'status' => 'disabled']);
check('disabled account → refused', quotaReason(fn() => $q->reserve($u2['id'], Ids::uuid())) === 'account_disabled');
$users->update($u2['id'], ['status' => 'active']);

section('Slot windows');
$defs = new SlotDefinitionRepository($fs);
check('overlapping windows rejected', throws(fn() => $defs->updateWindow('morning', '06:00', '13:00', true)));
check('end before start rejected', throws(fn() => $defs->updateWindow('morning', '12:00', '06:00', true)));
$defs->updateWindow('morning', '07:00', '12:00', true, null, 1);
freezeIst('2026-10-31 06:30:00');
check('admin-adjusted window takes effect (06:30 now closed)', quotaReason(fn() => $q->reserve($u1['id'], Ids::uuid())) === 'outside_window');
$defs->updateWindow('morning', '06:00', '12:00', true, null, 1);

section('Usage logs & summary index');
Clock::freeze(null);
$usage = new UsageLogRepository($fs);
$a = $usage->start(['request_type' => 'responses', 'user_id' => $u1['id'], 'azure_config_id' => 1, 'deployment_name' => 'gpt-4.1-mini', 'model_name' => 'gpt-4.1-mini', 'endpoint_path' => '/openai/v1/responses?api-key=SECRET']);
check('endpoint query string (could hold secrets) stripped', $a['endpoint_path'] === '/openai/v1/responses');
$usage->complete($a['id'], ['status' => 'success', 'http_status' => 200, 'input_tokens' => 1200, 'cached_input_tokens' => 200, 'output_tokens' => 300, 'total_tokens' => 1500, 'cost_status' => 'priced', 'cost_usd' => '0.00096', 'cost_inr' => '0.0797', 'usd_inr_rate' => '83.0000', 'latency_ms' => 812]);
$b = $usage->start(['request_type' => 'responses', 'user_id' => $u2['id'], 'deployment_name' => 'gpt-4.1-mini', 'model_name' => 'gpt-4.1-mini']);
$usage->complete($b['id'], ['status' => 'success', 'http_status' => 200]); // Azure returned no usage
$c = $usage->start(['request_type' => 'responses', 'user_id' => $u1['id'], 'deployment_name' => 'gpt-4.1-mini', 'model_name' => 'gpt-4.1-mini']);
$usage->complete($c['id'], ['status' => 'failed', 'http_status' => 429, 'error_code' => 'rate_limited', 'error_message' => 'Too many requests api-key: abcdef123456789']);
check('missing usage stays null (never invented)', $usage->get($b['id'])['input_tokens'] === null);
check('error message redacted', !str_contains($usage->get($c['id'])['error_message'], 'abcdef123456789'));
check('final status is immutable', throws(fn() => $usage->complete($a['id'], ['status' => 'failed']), LogicException::class));
$sum = $usage->summaryForMonth(Clock::billingPeriod())['days'][Clock::businessDate()]['all'];
check('summary: 3 requests, 2 success, 1 failed', $sum['requests'] === 3 && $sum['success'] === 2 && $sum['failed'] === 1, json_encode($sum));
check('summary: tokens and cost aggregated', $sum['input_tokens'] === 1200 && $sum['output_tokens'] === 300 && $sum['cost_usd'] === '0.00096');
check('summary: usage-missing and unpriced counted', $sum['usage_missing'] === 1 && $sum['unpriced'] === 1);
$usage->reprice($a['id'], ['cost_status' => 'priced', 'cost_usd' => '0.002', 'cost_inr' => '0.166', 'usd_inr_rate' => '83']);
$sum = $usage->summaryForMonth(Clock::billingPeriod())['days'][Clock::businessDate()]['all'];
check('reprice applies delta (no double count)', $sum['cost_usd'] === '0.002' && $sum['requests'] === 3, json_encode($sum['cost_usd']));
$before = $usage->summaryForMonth(Clock::billingPeriod())['days'];
$usage->rebuildSummary(Clock::billingPeriod());
check('rebuilt summary equals incremental summary', $usage->summaryForMonth(Clock::billingPeriod())['days'] == $before);

freezeIst('2026-11-02 09:15:00');
$h9 = $usage->start(['request_type' => 'chat', 'deployment_name' => 'gpt-4.1-mini']);
freezeIst('2026-11-02 15:40:00');
$h15 = $usage->start(['request_type' => 'chat', 'deployment_name' => 'gpt-4.1-mini']);
$usage->complete($h9['id'], ['status' => 'timeout']);   // completes hours later, still in its own hour file
check('requests partitioned into hourly files', is_file($tmp . '/storage/data/usage/2026/11/02/09.json') && is_file($tmp . '/storage/data/usage/2026/11/02/15.json'));
check('forDay merges hourly files in time order', array_column($usage->forDay('2026-11-02'), 'id') === [$h9['id'], $h15['id']]);
check('hourly summary bucket uses request hour', isset($usage->summaryForMonth('2026-11')['days']['2026-11-02']['hours']['09']));
Clock::freeze(null);

section('Pricing');
$pr = new PricingRepository($fs);
$p1 = $pr->add(['model_name' => 'gpt-4.1-mini', 'pricing_method' => 'per_token', 'input_per_1m' => '0.40', 'cached_input_per_1m' => '0.10', 'output_per_1m' => '1.60', 'effective_from' => '2026-01-01 00:00']);
$p2 = $pr->add(['model_name' => 'gpt-4.1-mini', 'pricing_method' => 'per_token', 'input_per_1m' => '0.50', 'output_per_1m' => '2.00', 'effective_from' => '2026-10-01 00:00']);
$p3 = $pr->add(['model_name' => 'gpt-4.1-mini', 'azure_config_id' => 1, 'pricing_method' => 'per_token', 'input_per_1m' => '0.44', 'output_per_1m' => '1.76', 'effective_from' => '2026-06-01 00:00']);
check('new price auto-closes the previous one', $pr->find($p1['id'])['effective_to'] === $p2['effective_from']);
check('historical request uses the old price', $pr->resolve('gpt-4.1-mini', 99, '2026-05-01T00:00:00Z', 'per_token')['id'] === $p1['id']);
check('current request uses the new price', $pr->resolve('gpt-4.1-mini', 99, '2026-10-15T00:00:00Z', 'per_token')['id'] === $p2['id']);
check('deployment-specific price beats model-wide', $pr->resolve('gpt-4.1-mini', 1, '2026-10-15T00:00:00Z', 'per_token')['id'] === $p3['id']);
check('no price → null (never guessed)', $pr->resolve('gpt-image-1', 2, '2026-10-15T00:00:00Z', 'per_image', '1024x1024', 'high') === null);
check('invalid price rejected', throws(fn() => $pr->add(['model_name' => 'x', 'pricing_method' => 'per_token', 'input_per_1m' => '-1', 'output_per_1m' => '1', 'effective_from' => '2026-01-01'])));

section('Images on disk');
$store = new ImageStore($fs, $tmp . '/storage/images', 200_000, ['image/png', 'image/jpeg', 'image/webp']);
$img = imagecreatetruecolor(4, 4);
ob_start();
imagepng($img);
$png = ob_get_clean();
$gen = Ids::make('img', '2026-10-19');
$saved = $store->save($gen, $png);
check('valid PNG saved in YYYY/MM/DD folder', $saved['storage_path'] === "2026/10/19/{$gen}.png" && is_file($store->resolve($saved['storage_path'])));
check('base64 decode strict', ImageStore::decodeBase64(base64_encode($png)) === $png && throws(fn() => ImageStore::decodeBase64('***')));
check('text disguised as image rejected', throws(fn() => $store->save(Ids::make('img'), "<?php echo 'x';"), StorageException::class));
check('oversize image rejected', throws(fn() => $store->save(Ids::make('img'), $png . str_repeat("\0", 300_000)), StorageException::class));
check('path traversal on download rejected', throws(fn() => $store->resolve('../../data/users.json'), StorageException::class));

section('Settings, budgets, Azure config');
check('setting type enforced (int)', throws(fn() => Settings::set('quota.daily_image_limit', 'three')));
check('unknown setting rejected', throws(fn() => Settings::set('quota.typo', 1)));
Settings::set('billing.usd_inr_rate', '84.25');
check('decimal setting stored as string', Settings::decimal('billing.usd_inr_rate') === '84.25');
$budgets = new BudgetRepository($fs);
$bud = $budgets->save(['budget_type' => 'daily_api', 'amount_usd' => '5', 'thresholds' => [50, 75, 90, 100], 'hard_limit' => true]);
check('duplicate org budget of same type rejected', throws(fn() => $budgets->save(['budget_type' => 'daily_api', 'amount_usd' => '9']), DuplicateException::class));
check('alert fires once per threshold per period', $budgets->recordAlertOnce($bud, '2026-10-19', 50, '2.6') && !$budgets->recordAlertOnce($bud, '2026-10-19', 50, '2.7'));
$az = new AzureConfigRepository($fs);
check('non-Azure endpoint rejected (key-leak guard)', throws(fn() => $az->create(['name' => 'evil', 'purpose' => 'image', 'endpoint_base' => 'https://evil.example.com/v1', 'deployment_name' => 'x', 'model_name' => 'x'])));
$img = $az->create(['name' => 'img', 'purpose' => 'image', 'endpoint_base' => 'https://snd-ai-2-resource.openai.azure.com/openai/v1', 'deployment_name' => 'gpt-image-1', 'model_name' => 'gpt-image-1', 'is_default' => true]);
$az->create(['name' => 'img2', 'purpose' => 'image', 'endpoint_base' => 'https://snd-ai-2-resource.openai.azure.com/openai/v1', 'deployment_name' => 'gpt-image-1-b', 'model_name' => 'gpt-image-1', 'is_default' => true]);
check('one default per purpose (new default replaces old)', $az->defaultFor('image')['deployment_name'] === 'gpt-image-1-b' && $az->defaultFor('chat')['deployment_name'] === 'gpt-4.1-mini');

section('Image generator (fake Azure, no network)');
putenv('TEST_IMG_KEY=test-key-123');
$imgCfg = $az->update((int)$az->defaultFor('image')['id'], ['api_key_env' => 'TEST_IMG_KEY', 'model_name' => 'gpt-image-1']);
(new PricingRepository($fs))->add(['model_name' => 'gpt-image-1', 'pricing_method' => 'image_tokens', 'input_per_1m' => '5',
    'image_output_per_1m' => '40', 'effective_from' => '2026-01-01 00:00']);
$png1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
$sent = [];
$fake = function (array $resp) use (&$sent) {
    return function ($method, $url, $headers, $body) use ($resp, &$sent) {
        $sent[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode((string)$body, true)];
        return $resp + ['status' => 0, 'headers' => [], 'body' => '', 'error' => null, 'errno' => 0, 'sent' => true];
    };
};
$okResp = ['status' => 200, 'headers' => ['apim-request-id' => 'abc'], 'body' => json_encode([
    'data' => [['b64_json' => $png1]], 'usage' => ['input_tokens' => 50, 'output_tokens' => 4160, 'total_tokens' => 4210],
])];
$imgStore = new ImageStore($fs, $tmp . '/storage/images');
$genFor = fn(array $resp) => new ImageGenerator($fs, new AzureClient($fs, $fake($resp)), $imgStore);
freezeIst('2026-10-21 13:05:00');
$gu = $users->create('Gen User', 'gen@example.com', 'gen-user-password-1');
$key1 = Ids::uuid();
$r = $genFor($okResp)->generate($gu, 'A lighthouse at sunset', '1024x1024', 'medium', $key1);
$g = $r['generation'];
check('success: image saved and record succeeded', $g['status'] === 'succeeded' && is_file($imgStore->resolve($g['storage_path'])));
check('request body uses deployment name as model, n=1, no response_format for gpt-image',
    $sent[0]['body']['model'] === $imgCfg['deployment_name'] && $sent[0]['body']['n'] === 1 && !isset($sent[0]['body']['response_format']));
check('api key sent only in api-key header', in_array('api-key: test-key-123', $sent[0]['headers'], true) && !str_contains($sent[0]['url'], 'test-key'));
check('cost from image_tokens price: (50×5 + 4160×40)/1M = $0.16665', Money::cmp($g['cost_usd'], '0.16665') === 0, (string)$g['cost_usd']);
$st = (new QuotaService($fs))->status($gu['id']);
check('afternoon slot used; morning expired → only evening left', $st['slots'][1]['state'] === 'used' && $st['remaining_today'] === 1);
$usageRec = (new UsageLogRepository($fs))->get($g['usage_log_id']);
check('usage log: success, tokens, image count, azure request id', $usageRec['status'] === 'success' && $usageRec['total_tokens'] === 4210
    && $usageRec['image_count'] === 1 && $usageRec['azure_request_id'] === 'abc' && $usageRec['cost_status'] === 'priced');
$n = count($sent);
$r2 = $genFor($okResp)->generate($gu, 'A lighthouse at sunset', '1024x1024', 'medium', $key1);
check('same request id replayed: same image, Azure not called again', $r2['replayed'] && $r2['generation']['id'] === $g['id'] && count($sent) === $n);
check('new request in a used slot → spec message', quotaReason(fn() => $genFor($okResp)->generate($gu, 'Another', '1024x1024', 'medium', Ids::uuid())) === 'slot_used');

$gu2 = $users->create('Gen Two', 'gen2@example.com', 'gen-user-password-2');
$blocked = ['status' => 400, 'body' => json_encode(['error' => ['code' => 'moderation_blocked', 'message' => 'Your request was rejected by the safety system.']])];
$msg = '';
try { $genFor($blocked)->generate($gu2, 'something blocked', '1024x1024', 'medium', Ids::uuid()); } catch (GeneratorException $e) { $msg = $e->getMessage(); }
check('content filter → friendly message', str_contains($msg, 'content safety'), $msg);
check('failed generation releases the slot (can retry same window)', (new QuotaService($fs))->status($gu2['id'])['slots'][1]['state'] === 'available');
$r3 = $genFor($okResp)->generate($gu2, 'something fine', '1536x1024', 'low', Ids::uuid());
check('retry after failure succeeds in the same slot', $r3['generation']['status'] === 'succeeded');

$gu3 = $users->create('Gen Three', 'gen3@example.com', 'gen-user-password-3');
$timeout = ['status' => 0, 'error' => 'Operation timed out', 'errno' => 28, 'sent' => true];
$reason = '';
try { $genFor($timeout)->generate($gu3, 'slow one', '1024x1024', 'medium', Ids::uuid()); } catch (GeneratorException $e) { $reason = $e->reason; }
check('timeout after sending → outcome unknown (not released)', $reason === 'unknown');
check('scope blocked until verified', quotaReason(fn() => $genFor($okResp)->generate($gu3, 'again', '1024x1024', 'medium', Ids::uuid())) === 'pending_reconciliation');
$refused = ['status' => 0, 'error' => 'Could not connect', 'errno' => 7, 'sent' => false];
$gu4 = $users->create('Gen Four', 'gen4@example.com', 'gen-user-password-4');
try { $genFor($refused)->generate($gu4, 'x', '1024x1024', 'medium', Ids::uuid()); } catch (GeneratorException $e) { $reason = $e->reason; }
check('connection refused (never sent) → failed and slot released', $reason === 'azure_failed'
    && (new QuotaService($fs))->status($gu4['id'])['slots'][1]['state'] === 'available');
check('bad size rejected before reserving', throws(fn() => $genFor($okResp)->generate($gu4, 'x', '999x999', 'medium', Ids::uuid()), GeneratorException::class)
    && (new QuotaService($fs))->status($gu4['id'])['slots'][1]['state'] === 'available');
putenv('TEST_IMG_KEY');
check('missing key → not configured, no slot used', (function () use ($genFor, $okResp, $gu4) {
    try { $genFor($okResp)->generate($gu4, 'x', '1024x1024', 'medium', Ids::uuid()); } catch (GeneratorException $e) { return $e->reason === 'not_configured'; }
    return false;
})());
check('legacy URL style', AzureClient::url(['endpoint_base' => 'https://x.openai.azure.com', 'api_style' => 'legacy', 'deployment_name' => 'dall-e-3', 'api_version' => '2024-02-01'], 'images/generations')
    === 'https://x.openai.azure.com/openai/deployments/dall-e-3/images/generations?api-version=2024-02-01');
check('dall-e-3 asks for base64', ImageModels::requestBody(['deployment_name' => 'd', 'model_name' => 'dall-e-3'], 'p', '1024x1024', 'hd')['response_format'] === 'b64_json');
Clock::freeze(null);

section('Content generation');
putenv('TEST_CONTENT_KEY=content-test-key');
$chatConfigs = new AzureConfigRepository($fs);
$chatCfg = $chatConfigs->defaultFor('chat');
$chatCfg = $chatConfigs->update((int)$chatCfg['id'], ['api_key_env' => 'TEST_CONTENT_KEY']);
$sent = [];
$chatResponse = ['status' => 200, 'body' => json_encode([
    'choices' => [['message' => ['content' => 'A fresh floral caption.'], 'finish_reason' => 'stop']],
    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120,
        'prompt_tokens_details' => ['cached_tokens' => 10]],
])];
$content = new \App\Services\ContentGenerator($fs, new AzureClient($fs, $fake($chatResponse)));
$contentKey = Ids::uuid();
$textRecord = $content->generate((int)$gu['id'], 'A floral caption', 'Social media caption', 'Friendly', $contentKey);
check('content generated and stored', $textRecord['status'] === 'success' && $textRecord['text'] === 'A fresh floral caption.');
check('content uses chat endpoint and deployment name', str_contains($sent[0]['url'], '/chat/completions')
    && $sent[0]['body']['model'] === $chatCfg['deployment_name'] && count($sent[0]['body']['messages']) === 2);
$content->generate((int)$gu['id'], 'A floral caption', 'Social media caption', 'Friendly', $contentKey);
check('content duplicate request does not call Azure again', count($sent) === 1);
check('content history is private', count($content->history((int)$gu['id'])) === 1 && $content->history((int)$gu2['id']) === []);
$textLog = (new UsageLogRepository($fs))->get($textRecord['usage_log_id']);
check('text usage logged without image tokens', $textLog['request_type'] === 'chat' && $textLog['total_tokens'] === 120
    && $textLog['cached_input_tokens'] === 10 && $textLog['image_count'] === 0 && $textLog['image_output_tokens'] === null);
check('invalid content options do not call Azure', throws(fn() => $content->generate((int)$gu['id'], 'x', 'bad', 'Friendly', Ids::uuid()), GeneratorException::class) && count($sent) === 1);
$emptyContent = new \App\Services\ContentGenerator($fs, new AzureClient($fs, $fake(['status' => 200, 'body' => '{"choices":[]}'])));
check('empty Azure content is a failed request', $emptyContent->generate((int)$gu['id'], 'Empty response', 'General content', 'Friendly', Ids::uuid())['status'] === 'failed');
$unknownContent = new \App\Services\ContentGenerator($fs, new AzureClient($fs, $fake($timeout)));
check('text timeout preserves unknown outcome', $unknownContent->generate((int)$gu['id'], 'Timeout', 'General content', 'Friendly', Ids::uuid())['status'] === 'unknown');
putenv('TEST_CONTENT_KEY');

section('Speech generation');
$speech = new \App\Services\SpeechGenerator($fs);
check('speech needs its own default deployment', $speech->unavailableReason() !== null);
putenv('TEST_SPEECH_KEY=speech-test-key');
$speechCfg = (new AzureConfigRepository($fs))->create([
    'name' => 'Speech test', 'purpose' => 'speech', 'endpoint_base' => 'https://test.openai.azure.com/openai/v1',
    'api_style' => 'v1', 'api_key_env' => 'TEST_SPEECH_KEY', 'deployment_name' => 'my-voice',
    'model_name' => 'gpt-4o-mini-tts', 'is_default' => true,
]);
check('speech default does not replace chat default', (new AzureConfigRepository($fs))->defaultFor('chat')['id'] === $chatCfg['id']);
$sent = [];
$audioBytes = 'ID3' . str_repeat("\0", 64);
$speech = new \App\Services\SpeechGenerator($fs, new AzureClient($fs, $fake([
    'status' => 200, 'headers' => ['content-type' => 'audio/mpeg'], 'body' => $audioBytes,
])));
$speechKey = Ids::uuid();
$speechRecord = $speech->generate((int)$gu['id'], 'Hello from the speech generator.', 'alloy', $speechKey);
check('speech returns a saved MP3', $speechRecord['status'] === 'success'
    && file_get_contents($speech->audioPath((int)$gu['id'], (int)$speechRecord['id'])) === $audioBytes);
check('speech uses audio endpoint and configured deployment', $sent[0]['url'] === 'https://test.openai.azure.com/openai/v1/audio/speech'
    && $sent[0]['body']['model'] === 'my-voice' && $sent[0]['body']['voice'] === 'alloy'
    && $sent[0]['body']['response_format'] === 'mp3' && in_array('Accept: audio/mpeg', $sent[0]['headers'], true));
$speech->generate((int)$gu['id'], 'Hello from the speech generator.', 'alloy', $speechKey);
check('duplicate speech request makes no second Azure call', count($sent) === 1);
check('another user cannot read the audio or history', $speech->audioPath((int)$gu2['id'], (int)$speechRecord['id']) === null
    && $speech->history((int)$gu2['id']) === []);
$speechLog = (new UsageLogRepository($fs))->get($speechRecord['usage_log_id']);
check('speech logged without invented token counts or prices', $speechLog['status'] === 'success' && $speechLog['request_type'] === 'speech'
    && $speechLog['total_tokens'] === null && $speechLog['cost_usd'] === null && $speechLog['cost_status'] === 'no_usage');
check('invalid voice rejected without Azure request', throws(fn() => $speech->generate((int)$gu['id'], 'Hello', 'bad', Ids::uuid()), GeneratorException::class) && count($sent) === 1);
check('speech text limit enforced before request', throws(fn() => $speech->generate((int)$gu['id'], str_repeat('a', 4097), 'alloy', Ids::uuid()), GeneratorException::class) && count($sent) === 1);
check('speech rejects forged request paths', throws(fn() => $speech->generate((int)$gu['id'], 'Hello', 'alloy', '../audio.mp3'), GeneratorException::class));
$badSpeech = new \App\Services\SpeechGenerator($fs, new AzureClient($fs, $fake(['status' => 200, 'body' => '{"error":"not audio"}'])));
$badRecord = $badSpeech->generate((int)$gu['id'], 'Invalid response', 'nova', Ids::uuid());
check('JSON response cannot be saved as MP3', $badRecord['status'] === 'unknown' && $badSpeech->audioPath((int)$gu['id'], (int)$badRecord['id']) === null);
$failedSpeech = new \App\Services\SpeechGenerator($fs, new AzureClient($fs, $fake(['status' => 401, 'body' => '{"error":{"code":"401","message":"Invalid key"}}'])));
check('Azure auth error is a failed speech request', $failedSpeech->generate((int)$gu['id'], 'Failure', 'echo', Ids::uuid())['status'] === 'failed');
$slowSpeech = new \App\Services\SpeechGenerator($fs, new AzureClient($fs, $fake($timeout)));
check('speech timeout remains unknown and is never retried', (function () use ($slowSpeech, $gu, &$sent) {
    $key = Ids::uuid();
    $r = $slowSpeech->generate((int)$gu['id'], 'Slow request', 'shimmer', $key);
    $n = count($sent);
    $slowSpeech->generate((int)$gu['id'], 'Slow request', 'shimmer', $key);
    return $r['status'] === 'unknown' && count($sent) === $n;
})());
putenv('TEST_SPEECH_KEY');
check('missing speech key prevents generation', $speech->unavailableReason() !== null);

require __DIR__ . '/automation.php';

section('Login: lockout, throttling, enumeration');
$login = new LoginService($fs);
$lu = $users->create('Lock Test', 'lock@example.com', 'lock-test-password-1');
$max = Settings::int('security.login_max_attempts', 5);
check('correct password signs in', $login->attempt('lock@example.com', 'lock-test-password-1', '10.0.0.1')['ok'] === true);
check('email is case-insensitive', $login->attempt('  LOCK@Example.com ', 'lock-test-password-1', '10.0.0.1')['ok'] === true);
$r = $login->attempt('lock@example.com', 'wrong-password-123', '10.0.0.1');
check('wrong password → generic message', !$r['ok'] && $r['error'] === LoginService::GENERIC_ERROR);
check('unknown email → the same generic message', $login->attempt('ghost@example.com', 'wrong-password-123', '10.0.0.1')['error'] === LoginService::GENERIC_ERROR);
for ($i = 1; $i < $max; $i++) {
    $r = $login->attempt('lock@example.com', 'wrong-password-123', '10.0.0.2');
}
check("{$max} failures lock the account", !$r['ok'] && str_starts_with($r['error'], 'Too many failed'), $r['error']);
check('locked_until stored on the user', LoginService::lockedFor($users->findWithSecret($lu['id'])) > 0);
check('correct password refused while locked', !$login->attempt('lock@example.com', 'lock-test-password-1', '10.0.0.3')['ok']);
for ($i = 0; $i < $max; $i++) {
    $g = $login->attempt('ghost2@example.com', 'wrong-password-123', '10.0.0.4');
}
check('unknown email locks the same way (no account enumeration)', str_starts_with($g['error'], 'Too many failed'), $g['error']);
$users->update($lu['id'], ['status' => 'disabled', 'locked_until' => null]);
(new RateLimiter($fs))->reset('login:email:lock@example.com');
$r = $login->attempt('lock@example.com', 'lock-test-password-1', '10.0.0.5');
check('disabled account refused even with the right password', !$r['ok'] && str_contains($r['error'], 'disabled'));
check('url redirect guard blocks off-site targets', Url::safeRedirectPath('//evil.com') === null
    && Url::safeRedirectPath('https://evil.com') === null && Url::safeRedirectPath('/\\evil.com') === null
    && Url::safeRedirectPath('/admin/audit?date=2026-10-19') === '/admin/audit?date=2026-10-19');

section('Helpers');
check('ids encode their IST date', Ids::dateOf('req_20261019_0123456789abcdef', 'req') === '2026-10-19');
check('forged id rejected', throws(fn() => Ids::dateOf('req_20261019_../../x', 'req')));
check('money add exact', Money::add('0.1', '0.2') === '0.3' && Money::add('0.00000001', '0.00000002') === '0.00000003');
freezeIst('2026-10-20 00:01:00');
check('00:01 IST is the new business day', Clock::businessDate() === '2026-10-20');
Clock::freeze(null);

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail ? 1 : 0);
