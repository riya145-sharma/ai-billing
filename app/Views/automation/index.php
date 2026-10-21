<?php
use App\Core\Auth;
use App\Core\Clock;
use App\Core\View;
use App\Repositories\AutomationConfigRepository;
/** @var bool $enabled @var array $allowance @var array $myJobs @var array $cfg @var array $today */

$limit = AutomationConfigRepository::limitFor($cfg);
$todayDate = Clock::businessDate();
$yesterday = Clock::now()->modify('-1 day')->format('Y-m-d');
$dayLabel = fn(string $d) => $d === $todayDate ? 'Today' : ($d === $yesterday ? 'Yesterday' : Clock::localAt($d, '12:00')->format('l, d M'));
$byDay = [];
foreach ($myJobs as $job) {
    $byDay[$job['date']][] = $job;
}
$slots = $today['slots'];
$next = $today['next'];
?>
<section class="auto-hero mb-4 <?= $enabled ? 'is-on' : 'is-off' ?>" aria-labelledby="auto-hero-title">
    <span class="auto-hero-icon" aria-hidden="true"><i class="bi bi-<?= $enabled ? 'alarm' : 'alarm-fill' ?>"></i></span>
    <div class="flex-grow-1">
        <h2 class="auto-hero-title" id="auto-hero-title">
            Daily motivation is <?= $enabled ? 'on' : 'off' ?> for you
        </h2>
        <p class="mb-0 auto-hero-sub">
            <?php if ($enabled): ?>
                Short messages with matching AI audio
                <strong><?= e(AutomationConfigRepository::describe($cfg)) ?></strong> in <?= e($cfg['language']) ?>. No action needed — only you can see and play them.
            <?php else: ?>
                Ask an administrator to switch it on. Manual content and speech generation have no daily limit.
            <?php endif; ?>
        </p>
    </div>
    <?php if ($enabled && $next): ?>
        <div class="auto-hero-next">
            <span class="small">Next message</span>
            <strong><?= e($next['date'] === $todayDate ? 'Today' : 'Tomorrow') ?>, <?= e($next['time']) ?></strong>
        </div>
    <?php endif; ?>
</section>

<?php if ($enabled): ?>
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-8">
        <section class="card h-100" aria-labelledby="timeline-title">
            <div class="card-header">
                <h2 class="card-title" id="timeline-title"><i class="bi bi-calendar-day" aria-hidden="true"></i> Today's schedule</h2>
                <span class="chip"><?= e(Clock::now()->format('d M Y')) ?> IST</span>
            </div>
            <div class="card-body">
                <ol class="timeline">
                    <?php foreach ($slots as $s): [$sl, $tone, $icon] = automation_status($s['status']); ?>
                        <li class="timeline-step st-<?= e($tone) ?>">
                            <span class="timeline-dot"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></span>
                            <span class="timeline-time"><?= e($s['time']) ?></span>
                            <span class="timeline-label"><?= e($sl) ?></span>
                            <?php if (!empty($s['job']['theme'])): ?><span class="timeline-theme"><?= e($s['job']['theme']) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </section>
    </div>
    <div class="col-12 col-xl-4">
        <section class="card h-100" aria-labelledby="allowance-title">
            <div class="card-header"><h2 class="card-title" id="allowance-title"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Today's allowance</h2></div>
            <div class="card-body">
                <?php foreach (['content' => ['Content', 'pencil-square'], 'speech' => ['Speech', 'soundwave']] as $kind => [$label, $icon]): $a = $allowance[$kind]; ?>
                    <div class="allow-row">
                        <span class="allow-label"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i> <?= e($label) ?></span>
                        <?= pips((int)$a['remaining'], (int)$limit, (int)$a['remaining'] . ' of ' . (int)$limit . ' left') ?>
                        <span class="allow-num"><?= (int)$a['remaining'] ?>/<?= (int)$limit ?> left</span>
                    </div>
                <?php endforeach; ?>
                <p class="small text-body-secondary mt-3 mb-0">Manual and automatic generations share this limit. Processing requests hold a place; failed ones don't count. Resets at midnight IST.</p>
            </div>
        </section>
    </div>
</div>
<?php endif; ?>

<section class="mb-4" aria-labelledby="mine-title">
    <div class="section-head">
        <h2 class="section-title" id="mine-title"><i class="bi bi-chat-quote" aria-hidden="true"></i> Your messages</h2>
        <span class="small text-body-secondary">Last 7 days</span>
    </div>
    <?php if (!$myJobs): ?>
        <div class="card"><div class="empty-state">
            <i class="bi bi-chat-heart" aria-hidden="true"></i>
            <p>No automatic messages yet.</p>
            <?php if ($enabled): ?><p class="small text-body-secondary">They appear here shortly after each scheduled time.</p><?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?php foreach ($byDay as $date => $jobs): ?>
        <div class="msg-slider mb-3" data-slider>
        <div class="day-head">
            <h3 class="day-heading" id="day-<?= e($date) ?>"><?= e($dayLabel($date)) ?> <span class="day-count"><?= count($jobs) ?> <?= count($jobs) === 1 ? 'message' : 'messages' ?></span></h3>
            <div class="slider-nav" data-slider-nav hidden>
                <button type="button" class="slider-btn" data-slider-prev aria-label="Previous messages"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                <button type="button" class="slider-btn" data-slider-next aria-label="Next messages"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="msg-rail" data-slider-rail tabindex="0" role="region" aria-labelledby="day-<?= e($date) ?>">
            <?php foreach ($jobs as $job):
                $h = (int)substr($job['time'], 0, 2);
                [$part, $partIcon, $partClass] = $h < 12 ? ['Morning', 'sunrise', 'tod-morning'] : ($h < 17 ? ['Afternoon', 'sun', 'tod-afternoon'] : ['Evening', 'moon-stars', 'tod-evening']);
                $hasText = $job['content']['state'] === 'success';
                $hasAudio = $job['speech']['state'] === 'success'; ?>
                <article class="msg <?= $partClass ?>" aria-label="<?= e($part . ' message, ' . $job['time']) ?>">
                    <header class="msg-head">
                        <span class="msg-icon" aria-hidden="true"><i class="bi bi-<?= $partIcon ?>"></i></span>
                        <div class="msg-meta">
                            <span class="msg-part"><?= e($part) ?> motivation</span>
                            <span class="msg-sub"><?= e($job['time']) ?> IST · <?= e($job['theme']) ?></span>
                        </div>
                        <?= automation_badge($job['status']) ?>
                    </header>

                    <?php if ($hasText): ?>
                        <div class="msg-body">
                            <i class="bi bi-quote msg-quote" aria-hidden="true"></i>
                            <p class="msg-text text-break" id="msg-<?= e($job['id']) ?>" data-clamp><?= nl2br(e($job['content']['text'])) ?></p>
                            <button type="button" class="msg-more d-none" data-clamp-toggle aria-controls="msg-<?= e($job['id']) ?>" aria-expanded="false">Read more</button>
                        </div>
                    <?php elseif (empty($job['message'])): ?>
                        <div class="msg-body msg-pending"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Your message is being prepared.</div>
                    <?php endif; ?>

                    <?php if (!empty($job['message'])): ?><p class="msg-note"><i class="bi bi-info-circle" aria-hidden="true"></i> <?= e($job['message']) ?></p><?php endif; ?>

                    <?php if ($hasAudio): ?>
                        <footer class="msg-player" data-player>
                            <audio controls preload="none" src="<?= url('/automation/audio/' . $job['id']) ?>" aria-label="AI-generated audio for this message"></audio>
                            <button type="button" class="msg-play" data-play aria-label="Play audio" hidden><i class="bi bi-play-fill" aria-hidden="true"></i></button>
                            <div class="msg-track" hidden>
                                <input type="range" class="msg-seek" min="0" max="100" step="0.1" value="0" data-seek aria-label="Seek">
                                <div class="msg-times"><span data-cur>0:00</span><span data-dur>—</span></div>
                            </div>
                            <a class="msg-dl" href="<?= url('/automation/audio/' . $job['id'], ['download' => '1']) ?>" download title="Download MP3"><i class="bi bi-download" aria-hidden="true"></i><span class="visually-hidden">Download MP3</span></a>
                        </footer>
                        <p class="msg-ai"><i class="bi bi-robot" aria-hidden="true"></i> AI-generated voice</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="slider-dots" data-slider-dots hidden></div>
        </div>
    <?php endforeach; ?>
</section>

<?php if (Auth::isAdmin()):
    /** @var array $users @var array $userNames @var array $todayJobs @var array $upcoming @var array $heartbeat @var array $voices */
    $lastStart = $heartbeat['last_started_at'] ?? null;
    $stale = $lastStart === null || Clock::now()->getTimestamp() - Clock::parseIso($lastStart)->getTimestamp() > 15 * 60;
    $attention = count(array_filter($todayJobs, fn($j) => in_array($j['status'], ['failed', 'uncertain', 'blocked'], true)));
    $enabledCount = count($cfg['users']);
    $tabs = [
        'overview' => ['Overview', 'activity', $stale ? '!' : null],
        'users'    => ['Users', 'people', (string)$enabledCount],
        'settings' => ['Schedule & content', 'sliders', null],
        'jobs'     => ["Today's jobs", 'list-check', $attention ? (string)$attention : (string)count($todayJobs)],
    ];
?>
<section aria-labelledby="admin-title">
    <div class="section-head">
        <h2 class="section-title" id="admin-title"><i class="bi bi-shield-lock" aria-hidden="true"></i> Administration</h2>
    </div>
    <div class="card">
        <ul class="nav nav-tabs admin-tabs" role="tablist">
            <?php $first = true; foreach ($tabs as $id => [$label, $icon, $count]): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link<?= $first ? ' active' : '' ?>" id="tab-<?= $id ?>" data-bs-toggle="tab" data-bs-target="#<?= $id ?>" type="button" role="tab" aria-controls="<?= $id ?>" aria-selected="<?= $first ? 'true' : 'false' ?>">
                        <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i> <?= e($label) ?>
                        <?php if ($count !== null): ?><span class="tab-count<?= ($id === 'overview' || ($id === 'jobs' && $attention)) ? ' is-alert' : '' ?>"><?= e($count) ?></span><?php endif; ?>
                    </button>
                </li>
            <?php $first = false; endforeach; ?>
        </ul>

        <div class="tab-content">
            <!-- Overview -->
            <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="tab-overview" tabindex="0">
                <div class="row g-0">
                    <div class="col-12 col-xl-5 pane-split">
                        <div class="card-body">
                            <h3 class="pane-title">Cron status</h3>
                            <?php if ($stale): ?>
                                <div class="notice notice-warning mb-3"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>
                                    <?= $lastStart === null ? 'The automation cron has never run.' : 'The automation cron has not run for ' . e(str_replace(' ago', '', View::relative($lastStart))) . '.' ?>
                                    Check the scheduled task that runs <code>bin/automation-cron.php</code> every 5 minutes (see README).</div></div>
                            <?php else: ?>
                                <div class="notice notice-success mb-3"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><div>The cron is running normally. Last run <?= e(View::relative($lastStart)) ?>.</div></div>
                            <?php endif; ?>
                            <?php if (Clock::tz()->getName() !== 'Asia/Kolkata'): ?>
                                <div class="notice notice-warning mb-3"><i class="bi bi-globe" aria-hidden="true"></i><div>APP_TIMEZONE is <?= e(Clock::tz()->getName()) ?>. Set it to Asia/Kolkata so schedules and daily limits use IST.</div></div>
                            <?php endif; ?>
                            <dl class="stat-list">
                                <div><dt>Last heartbeat</dt><dd><?= e(View::dateTime($lastStart, 'd M, H:i:s')) ?></dd></div>
                                <div><dt>Last finished</dt><dd><?= e(View::dateTime($heartbeat['last_finished_at'] ?? null, 'd M, H:i:s')) ?></dd></div>
                                <div><dt>Last result</dt><dd><?= e(ucfirst((string)($heartbeat['last_status'] ?? '—'))) ?></dd></div>
                                <?php if (!empty($heartbeat['last_skipped_at'])): ?>
                                    <div><dt>Last overlap skipped</dt><dd><?= e(View::dateTime($heartbeat['last_skipped_at'], 'd M, H:i:s')) ?></dd></div>
                                <?php endif; ?>
                            </dl>
                            <?php if (!empty($heartbeat['last_error'])): ?>
                                <div class="notice notice-danger mt-3"><i class="bi bi-x-circle" aria-hidden="true"></i><div>Last run error: <?= e($heartbeat['last_error']) ?> — details are in storage/logs.</div></div>
                            <?php endif; ?>
                            <?php if (!empty($heartbeat['last_stats'])): $s = $heartbeat['last_stats']; ?>
                                <p class="small text-body-secondary mt-3 mb-0">Last run: <?= (int)$s['content_success'] ?> texts, <?= (int)$s['speech_success'] ?> audio files,
                                    <?= (int)$s['failed_attempts'] ?> failed attempts, <?= (int)$s['retries_scheduled'] ?> retries scheduled.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12 col-xl-7">
                        <div class="card-body pb-0"><h3 class="pane-title mb-0">Upcoming jobs</h3></div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead><tr><th scope="col">When (IST)</th><th scope="col">User</th><th scope="col">Theme</th><th scope="col">Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($upcoming as $u): ?>
                                    <tr>
                                        <td class="text-nowrap"><?= e($u['date'] === $todayDate ? 'Today' : 'Tomorrow') ?> <strong><?= e($u['time']) ?></strong></td>
                                        <td><?= e($userNames[$u['user_id']] ?? ('User #' . $u['user_id'])) ?></td>
                                        <td class="small text-body-secondary"><?= e($u['theme'] ?? 'Chosen when planned') ?></td>
                                        <td><?= automation_badge($u['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$upcoming): ?><tr><td colspan="4" class="text-body-secondary">Nothing scheduled. Enable automation for at least one user.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Users -->
            <div class="tab-pane fade" id="users" role="tabpanel" aria-labelledby="tab-users" tabindex="0">
                <form method="post" action="<?= url('/admin/automation/users') ?>" data-once>
                    <?= csrf_field() ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th scope="col">Automation</th><th scope="col">User</th><th scope="col">Content left today</th><th scope="col">Speech left today</th></tr></thead>
                            <tbody>
                            <?php foreach ($users as $row): $u = $row['user']; $a = $row['allowance']; $on = isset($cfg['users'][(string)$u['id']]); $active = $u['status'] === 'active'; ?>
                                <tr>
                                    <td><div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch" name="user_ids[]" value="<?= (int)$u['id'] ?>" id="auto_u<?= (int)$u['id'] ?>" <?= $on ? 'checked' : '' ?> <?= $active ? '' : 'disabled' ?>>
                                        <label class="form-check-label visually-hidden" for="auto_u<?= (int)$u['id'] ?>">Automation for <?= e($u['name']) ?></label>
                                    </div></td>
                                    <td><div class="d-flex align-items-center gap-2">
                                        <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string)$u['name'], 0, 1))) ?></span>
                                        <div><div class="fw-semibold"><?= e($u['name']) ?></div><div class="small text-body-secondary"><?= e($u['email']) ?><?= $active ? '' : ' · disabled account' ?></div></div>
                                    </div></td>
                                    <?php foreach (['content', 'speech'] as $kind): $k = $a[$kind]; ?>
                                        <td class="small">
                                            <?php if ($a['limit'] === null): ?>
                                                <span class="text-body-secondary">No limit</span> · <?= (int)$k['used'] ?> used
                                            <?php else: ?>
                                                <div class="d-flex align-items-center gap-2"><?= pips((int)$k['remaining'], (int)$a['limit'], (int)$k['remaining'] . ' of ' . (int)$a['limit'] . ' left') ?><strong><?= (int)$k['remaining'] ?>/<?= (int)$a['limit'] ?></strong></div>
                                                <div class="text-body-secondary"><?= (int)$k['manual_used'] ?> manual, <?= (int)$k['auto_used'] ?> automatic<?= $k['reserved'] ? ', ' . (int)$k['reserved'] . ' held' : '' ?><?= $k['failed'] ? ', ' . (int)$k['failed'] . ' failed (not counted)' : '' ?></div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body border-top d-flex flex-wrap gap-3 align-items-center justify-content-between">
                        <p class="small text-body-secondary mb-0 flex-grow-1 pane-note">Enabled users get <?= (int)$limit ?> messages with audio per day and a shared daily limit of <?= (int)$limit ?> content and <?= (int)$limit ?> speech generations (manual + automatic). Turning automation on mid-day catches up on today's earlier times.</p>
                        <button class="btn btn-primary" type="submit"><span class="btn-label">Save users</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                    </div>
                </form>
            </div>

            <!-- Settings -->
            <div class="tab-pane fade" id="settings" role="tabpanel" aria-labelledby="tab-settings" tabindex="0">
                <div class="card-body">
                    <form method="post" action="<?= url('/admin/automation/settings') ?>" data-once class="settings-form">
                        <?= csrf_field() ?>
                        <div class="row g-4">
                            <div class="col-12 col-lg-5">
                                <fieldset class="mb-3">
                                    <legend class="form-label fs-6 fw-semibold">Generation times (IST)</legend>
                                    <div class="row g-2">
                                        <?php for ($i = 0; $i < AutomationConfigRepository::MAX_TIMES; $i++): ?>
                                            <div class="col-4"><label class="visually-hidden" for="time<?= $i ?>">Time <?= $i + 1 ?></label>
                                                <input class="form-control" type="time" id="time<?= $i ?>" name="times[]" value="<?= e($cfg['times'][$i] ?? '') ?>"></div>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="form-text">Up to <?= (int)AutomationConfigRepository::MAX_TIMES ?> times. Clear a box to remove that run. Ignored while "Repeat" is on.</div>
                                </fieldset>
                                <div class="mb-3"><label class="form-label fw-semibold" for="interval_minutes">Repeat</label>
                                    <select class="form-select" id="interval_minutes" name="interval_minutes" aria-describedby="intervalHelp">
                                        <?php foreach (AutomationConfigRepository::INTERVALS as $m): ?>
                                            <option value="<?= (int)$m ?>" <?= (int)$cfg['interval_minutes'] === $m ? 'selected' : '' ?>><?= $m === 0 ? 'Off — use the times above' : e(AutomationConfigRepository::describe(['interval_minutes' => $m])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text" id="intervalHelp">Counted from when you save: saved at 11:52 with every 30 minutes → 12:22, 12:52, … The daily limit rises to match (<?= 1440 / 30 ?> a day for 30 minutes). Missed runs are not caught up.</div>
                                </div>
                                <div class="mb-3"><label class="form-label fw-semibold" for="language">Language</label>
                                    <input class="form-control" id="language" name="language" value="<?= e($cfg['language']) ?>" maxlength="40" required list="languageList">
                                    <datalist id="languageList"><?php foreach (['English', 'Hindi', 'Marathi', 'Tamil', 'Telugu', 'Bengali', 'Gujarati', 'Kannada', 'Spanish', 'French'] as $l): ?><option value="<?= e($l) ?>"><?php endforeach; ?></datalist>
                                </div>
                                <div class="mb-3"><label class="form-label fw-semibold" for="voice">Voice</label>
                                    <select class="form-select" id="voice" name="voice">
                                        <?php foreach ($voices as $v): ?><option value="<?= e($v) ?>" <?= $cfg['voice'] === $v ? 'selected' : '' ?>><?= e(ucfirst($v)) ?></option><?php endforeach; ?>
                                    </select></div>
                            </div>
                            <div class="col-12 col-lg-7">
                                <label class="form-label fw-semibold" for="themes">Motivational themes</label>
                                <textarea class="form-control" id="themes" name="themes" rows="9" required><?= e(implode("\n", $cfg['themes'])) ?></textarea>
                                <div class="form-text">One per line. Each run uses a different theme, rotating daily.</div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-3 align-items-center mt-3">
                            <button class="btn btn-primary" type="submit"><span class="btn-label">Save settings</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                            <?php if ($cfg['updated_at']): ?><span class="small text-body-secondary">Last saved <?= e(View::dateTime($cfg['updated_at'])) ?></span><?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Today's jobs -->
            <div class="tab-pane fade" id="jobs" role="tabpanel" aria-labelledby="tab-jobs" tabindex="0">
                <div class="card-body pb-2"><p class="small text-body-secondary mb-0"><i class="bi bi-lock" aria-hidden="true"></i> Message text and audio stay private to each user.</p></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th scope="col">Time</th><th scope="col">User</th><th scope="col">Theme</th><th scope="col">Status</th><th scope="col">Attempts</th><th scope="col">Details</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                        <tbody>
                        <?php foreach ($todayJobs as $job): ?>
                            <tr>
                                <td class="text-nowrap fw-semibold"><?= e($job['time']) ?></td>
                                <td><?= e($userNames[$job['user_id']] ?? ('User #' . $job['user_id'])) ?></td>
                                <td class="small"><?= e($job['theme']) ?></td>
                                <td><?= automation_badge($job['status']) ?></td>
                                <td class="small text-nowrap">Text <?= (int)$job['content']['attempts'] ?> · Speech <?= (int)$job['speech']['attempts'] ?></td>
                                <td class="small"><?= e($job['message'] ?? '') ?>
                                    <?php if (!empty($job['content']['quote_warning'])): ?><div class="text-warning-emphasis">The text may contain a quotation; review it.</div><?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (in_array($job['status'], ['failed', 'uncertain'], true)): ?>
                                        <form method="post" action="<?= url('/admin/automation/jobs/' . $job['id'] . '/retry') ?>"
                                              <?= $job['status'] === 'uncertain' ? 'data-confirm="Only retry if the Azure usage log shows this request produced nothing. Retrying an uncertain request can create a duplicate charge. Continue?"' : '' ?>>
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Retry</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$todayJobs): ?><tr><td colspan="7" class="text-body-secondary">No jobs planned yet today. Jobs appear after the next cron run.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>
