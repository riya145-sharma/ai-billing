<?php
use App\Core\Auth;
use App\Core\Clock;
use App\Core\View;
use App\Repositories\AutomationConfigRepository;
/** @var bool $enabled @var array $allowance @var array $cfg @var array $today @var ?array $latest */

$user = Auth::user();
$now = Clock::now();
$hour = (int)$now->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim((string)$user['name']))[0] ?? '';
$limit = AutomationConfigRepository::DAILY_LIMIT;
$slots = $today['slots'];
$delivered = count(array_filter($slots, fn($s) => $s['status'] === 'completed'));
$next = $today['next'];
?>
<section class="dash-hero mb-4" aria-labelledby="hero-title">
    <div>
        <p class="dash-hero-date"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= e($now->format('l, j F Y')) ?></p>
        <h2 class="dash-hero-title" id="hero-title"><?= e($greeting) ?>, <?= e($firstName) ?></h2>
        <p class="dash-hero-sub mb-0">
            <?php if ($enabled && $next): ?>
                Your next motivational message arrives
                <strong><?= e($next['date'] === Clock::businessDate() ? 'today' : 'tomorrow') ?> at <?= e($next['time']) ?> IST</strong>.
            <?php else: ?>
                Create content and lifelike audio with AI in a few clicks.
            <?php endif; ?>
        </p>
    </div>
    <div class="dash-hero-actions">
        <a class="btn btn-light" href="<?= url('/content') ?>"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i> New content</a>
        <a class="btn btn-outline-light" href="<?= url('/speech') ?>"><i class="bi bi-soundwave me-1" aria-hidden="true"></i> New speech</a>
    </div>
</section>

<div class="row g-3 mb-4">
    <?php foreach (['content' => ['Content generations', 'pencil-square'], 'speech' => ['Speech generations', 'soundwave']] as $kind => [$label, $icon]):
        $a = $allowance[$kind]; ?>
        <div class="col-12 col-md-4">
            <section class="card h-100 tile">
                <div class="card-body">
                    <div class="tile-icon"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
                    <div class="kpi-label"><?= e($label) ?> left today</div>
                    <?php if ($allowance['limit'] === null): ?>
                        <div class="kpi-value">Unlimited</div>
                        <div class="kpi-sub"><?= (int)$a['used'] ?> used today</div>
                    <?php else: ?>
                        <div class="kpi-value"><?= (int)$a['remaining'] ?> <span class="kpi-of">of <?= (int)$allowance['limit'] ?></span></div>
                        <div class="mt-2"><?= pips((int)$a['remaining'], (int)$allowance['limit'], (int)$a['remaining'] . ' of ' . (int)$allowance['limit'] . ' left') ?></div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    <?php endforeach; ?>
    <div class="col-12 col-md-4">
        <section class="card h-100 tile">
            <div class="card-body">
                <div class="tile-icon tile-icon-alt"><i class="bi bi-alarm" aria-hidden="true"></i></div>
                <div class="kpi-label">Daily motivation</div>
                <?php if ($enabled && $slots): ?>
                    <div class="kpi-value"><?= $delivered ?> <span class="kpi-of">of <?= count($slots) ?> delivered</span></div>
                    <ol class="mini-timeline mt-2" aria-label="Today's messages">
                        <?php foreach ($slots as $s): [$sl, $tone] = automation_status($s['status']); ?>
                            <li class="st-<?= e($tone) ?>" title="<?= e($s['time'] . ' — ' . $sl) ?>"><span class="visually-hidden"><?= e($s['time'] . ': ' . $sl) ?></span><?= e($s['time']) ?></li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <div class="kpi-value kpi-value-sm">Not enabled</div>
                    <div class="kpi-sub">An administrator can switch it on for you.</div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <section class="card h-100" aria-labelledby="latest-title">
            <div class="card-header">
                <h2 class="card-title" id="latest-title"><i class="bi bi-chat-quote" aria-hidden="true"></i> Latest motivation</h2>
                <a class="small" href="<?= url('/automation') ?>">All messages <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="card-body">
                <?php if ($latest): ?>
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <span class="time-pill"><i class="bi bi-clock" aria-hidden="true"></i> <?= e(Clock::localAt($latest['date'], $latest['time'])->format('d M, H:i')) ?> IST</span>
                        <span class="chip"><?= e($latest['theme']) ?></span>
                    </div>
                    <blockquote class="message-text mb-3"><?= nl2br(e($latest['content']['text'])) ?></blockquote>
                    <?php if ($latest['speech']['state'] === 'success'): ?>
                        <div class="audio-row">
                            <audio controls preload="none" src="<?= url('/automation/audio/' . $latest['id']) ?>" aria-label="AI-generated audio for this message"></audio>
                            <a class="btn btn-sm btn-outline-primary" href="<?= url('/automation/audio/' . $latest['id'], ['download' => '1']) ?>" download><i class="bi bi-download" aria-hidden="true"></i><span class="visually-hidden">Download MP3</span></a>
                        </div>
                        <p class="small text-body-secondary mt-2 mb-0"><i class="bi bi-robot" aria-hidden="true"></i> This voice is AI-generated.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state compact">
                        <i class="bi bi-chat-heart" aria-hidden="true"></i>
                        <p><?= $enabled ? 'Your first message will appear here after the next scheduled time.' : 'No automatic messages yet.' ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-5">
        <section class="card h-100" aria-labelledby="actions-title">
            <div class="card-header"><h2 class="card-title" id="actions-title"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Quick actions</h2></div>
            <div class="action-list">
                <a class="action-item" href="<?= url('/content') ?>">
                    <span class="action-icon a-blue"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
                    <span class="action-body"><span class="action-title">Content generator</span><span class="action-desc">Captions, product descriptions, blog posts and emails.</span></span>
                    <i class="bi bi-chevron-right action-go" aria-hidden="true"></i>
                </a>
                <a class="action-item" href="<?= url('/speech') ?>">
                    <span class="action-icon a-violet"><i class="bi bi-soundwave" aria-hidden="true"></i></span>
                    <span class="action-body"><span class="action-title">Text to speech</span><span class="action-desc">Turn text into spoken audio and download an MP3.</span></span>
                    <i class="bi bi-chevron-right action-go" aria-hidden="true"></i>
                </a>
                <a class="action-item" href="<?= url('/automation') ?>">
                    <span class="action-icon a-green"><i class="bi bi-alarm" aria-hidden="true"></i></span>
                    <span class="action-body"><span class="action-title">Automation</span><span class="action-desc"><?= $enabled ? 'Daily messages at ' . e(implode(', ', $cfg['times'])) . ' IST.' : 'Daily motivational messages with audio.' ?></span></span>
                    <i class="bi bi-chevron-right action-go" aria-hidden="true"></i>
                </a>
            </div>
        </section>
    </div>

<?php if (Auth::isAdmin()):
    /** @var array $heartbeat @var array $jobCounts @var int $jobTotal */
    $lastStart = $heartbeat['last_started_at'] ?? null;
    $stale = $lastStart === null || Clock::now()->getTimestamp() - Clock::parseIso($lastStart)->getTimestamp() > 15 * 60;
    $problems = ($jobCounts['failed'] ?? 0) + ($jobCounts['uncertain'] ?? 0) + ($jobCounts['blocked'] ?? 0);
?>
    <div class="col-12">
        <section class="card" aria-labelledby="health-title">
            <div class="card-header">
                <h2 class="card-title" id="health-title"><i class="bi bi-activity" aria-hidden="true"></i> System health</h2>
                <a class="small" href="<?= url('/automation') ?>#overview">Automation details <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="health-grid">
                <div class="health-item">
                    <span class="health-label">Automation cron</span>
                    <?= $stale ? '<span class="st-pill st-bad"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Not running</span>'
                               : '<span class="st-pill st-good"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Running</span>' ?>
                    <span class="health-sub">Last run <?= e(View::relative($lastStart)) ?></span>
                </div>
                <div class="health-item">
                    <span class="health-label">Today's jobs delivered</span>
                    <span class="health-value"><?= (int)($jobCounts['completed'] ?? 0) ?> <span class="kpi-of">of <?= (int)$jobTotal ?></span></span>
                </div>
                <div class="health-item">
                    <span class="health-label">Needs attention</span>
                    <span class="health-value<?= $problems ? ' text-danger' : '' ?>"><?= (int)$problems ?></span>
                    <span class="health-sub">Failed, uncertain or blocked</span>
                </div>
            </div>
        </section>
    </div>
<?php endif; ?>
</div>
