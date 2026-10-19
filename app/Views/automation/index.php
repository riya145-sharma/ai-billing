<?php
use App\Core\Auth;
use App\Core\Clock;
use App\Core\View;
use App\Repositories\AutomationConfigRepository;
/** @var bool $enabled @var array $allowance @var array $myJobs @var array $cfg */

$badges = [
    'scheduled' => ['Scheduled', 'secondary'], 'queued' => ['Queued', 'info'], 'running' => ['Running', 'info'],
    'waiting_retry' => ['Retrying', 'warning'], 'completed' => ['Completed', 'success'], 'failed' => ['Failed', 'danger'],
    'uncertain' => ['Uncertain', 'warning'], 'blocked' => ['Blocked', 'warning'], 'skipped' => ['Skipped', 'secondary'],
    'expired' => ['Expired', 'secondary'], 'due' => ['Due — waiting for cron', 'info'], 'upcoming' => ['Upcoming', 'secondary'],
];
$badge = function (string $status) use ($badges): string {
    [$label, $tone] = $badges[$status] ?? [ucfirst($status), 'secondary'];
    return '<span class="badge bg-' . $tone . '-subtle text-' . $tone . '-emphasis">' . e($label) . '</span>';
};
$limit = AutomationConfigRepository::DAILY_LIMIT;
?>
<div class="row g-3">
    <div class="col-12 col-xl-4">
        <section class="card h-100" aria-labelledby="allowance-title">
            <div class="card-header"><h2 class="card-title" id="allowance-title"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Today's allowance</h2>
                <span class="chip"><?= e(View::dateTime(Clock::nowIso(), 'd M Y')) ?> IST</span></div>
            <div class="card-body">
                <?php if ($enabled): ?>
                    <dl class="stat-list">
                        <?php foreach (['content' => 'Content generations left', 'speech' => 'Speech generations left'] as $kind => $label): $a = $allowance[$kind]; ?>
                            <div><dt><?= e($label) ?></dt><dd><?= (int)$a['remaining'] ?> of <?= (int)$limit ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <p class="small text-body-secondary mt-3 mb-0">Manual and automatic generations share this limit. Requests that are processing or awaiting verification hold a place; failed requests do not count. Resets at midnight IST.</p>
                <?php else: ?>
                    <p class="mb-0">Daily motivation is not enabled for your account. Manual content and speech generation have no daily limit.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-12 col-xl-8">
        <section class="card h-100" aria-labelledby="schedule-title">
            <div class="card-header"><h2 class="card-title" id="schedule-title"><i class="bi bi-alarm" aria-hidden="true"></i> Daily motivation</h2></div>
            <div class="card-body">
                <p class="mb-2">Three short motivational messages, each with matching AI-generated audio, are created automatically every day at
                    <strong><?= e(implode(', ', $cfg['times'])) ?> IST</strong> in <?= e($cfg['language']) ?>. No action is needed from you.</p>
                <p class="small text-body-secondary mb-0">Messages appear below shortly after each scheduled time. Only you can see your messages and play your audio.</p>
            </div>
        </section>
    </div>

    <div class="col-12">
        <section class="card" aria-labelledby="mine-title">
            <div class="card-header"><h2 class="card-title" id="mine-title"><i class="bi bi-chat-quote" aria-hidden="true"></i> Your messages</h2>
                <span class="small text-body-secondary">Last 7 days</span></div>
            <div class="card-body">
                <?php if (!$myJobs): ?><p class="mb-0">No automatic messages yet.</p><?php endif; ?>
                <?php foreach ($myJobs as $job): ?>
                    <article class="border rounded p-3 mb-3">
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                            <strong><?= e(View::dateTime(Clock::toIso(Clock::localAt($job['date'], $job['time'])), 'd M Y, H:i')) ?> IST</strong>
                            <span class="chip"><?= e($job['theme']) ?></span>
                            <?= $badge($job['status']) ?>
                        </div>
                        <?php if ($job['content']['state'] === 'success'): ?>
                            <p class="text-break mb-3"><?= nl2br(e($job['content']['text'])) ?></p>
                        <?php endif; ?>
                        <?php if ($job['speech']['state'] === 'success'): ?>
                            <audio class="w-100 mb-2" controls preload="none" src="<?= url('/automation/audio/' . $job['id']) ?>" aria-label="AI-generated audio for this message"></audio>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <a class="btn btn-sm btn-outline-primary" href="<?= url('/automation/audio/' . $job['id'], ['download' => '1']) ?>" download><i class="bi bi-download" aria-hidden="true"></i> Download MP3</a>
                                <span class="small text-body-secondary">This voice is AI-generated.</span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($job['message'])): ?><p class="small mb-0 mt-2"><?= e($job['message']) ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

<?php if (Auth::isAdmin()):
    /** @var array $users @var array $userNames @var array $todayJobs @var array $upcoming @var array $heartbeat @var array $voices */
    $lastStart = $heartbeat['last_started_at'] ?? null;
    $stale = $lastStart === null || Clock::now()->getTimestamp() - Clock::parseIso($lastStart)->getTimestamp() > 15 * 60;
?>
    <div class="col-12"><h2 class="h6 text-uppercase text-body-secondary mt-3 mb-0">Administration</h2></div>

    <div class="col-12 col-xl-5">
        <section class="card h-100" aria-labelledby="cron-title">
            <div class="card-header"><h2 class="card-title" id="cron-title"><i class="bi bi-activity" aria-hidden="true"></i> Cron status</h2></div>
            <div class="card-body">
                <?php if ($stale): ?>
                    <div class="alert alert-warning">
                        <?= $lastStart === null ? 'The automation cron has never run.' : 'The automation cron has not run for ' . e(str_replace(' ago', '', View::relative($lastStart))) . '.' ?>
                        Check the scheduled task that runs <code>bin/automation-cron.php</code> every 5 minutes (see README).
                    </div>
                <?php endif; ?>
                <?php if (Clock::tz()->getName() !== 'Asia/Kolkata'): ?>
                    <div class="alert alert-warning">APP_TIMEZONE is <?= e(Clock::tz()->getName()) ?>. Set it to Asia/Kolkata so schedules and daily limits use IST.</div>
                <?php endif; ?>
                <dl class="stat-list">
                    <div><dt>Last heartbeat</dt><dd><?= e(View::dateTime($lastStart, 'd M, H:i:s')) ?> <span class="small text-body-secondary">(<?= e(View::relative($lastStart)) ?>)</span></dd></div>
                    <div><dt>Last finished</dt><dd><?= e(View::dateTime($heartbeat['last_finished_at'] ?? null, 'd M, H:i:s')) ?></dd></div>
                    <div><dt>Last result</dt><dd><?= e(ucfirst((string)($heartbeat['last_status'] ?? '—'))) ?></dd></div>
                    <?php if (!empty($heartbeat['last_skipped_at'])): ?>
                        <div><dt>Last overlap skipped</dt><dd><?= e(View::dateTime($heartbeat['last_skipped_at'], 'd M, H:i:s')) ?></dd></div>
                    <?php endif; ?>
                </dl>
                <?php if (!empty($heartbeat['last_error'])): ?>
                    <div class="alert alert-danger mt-3 mb-0">Last run error: <?= e($heartbeat['last_error']) ?> — details are in storage/logs.</div>
                <?php endif; ?>
                <?php if (!empty($heartbeat['last_stats'])): $s = $heartbeat['last_stats']; ?>
                    <p class="small text-body-secondary mt-3 mb-0">Last run: <?= (int)$s['content_success'] ?> texts, <?= (int)$s['speech_success'] ?> audio files,
                        <?= (int)$s['failed_attempts'] ?> failed attempts, <?= (int)$s['retries_scheduled'] ?> retries scheduled.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-7">
        <section class="card h-100" aria-labelledby="upcoming-title">
            <div class="card-header"><h2 class="card-title" id="upcoming-title"><i class="bi bi-calendar-event" aria-hidden="true"></i> Upcoming jobs</h2></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">When (IST)</th><th scope="col">User</th><th scope="col">Theme</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($upcoming as $u): ?>
                        <tr>
                            <td class="text-nowrap"><?= e($u['date'] === Clock::businessDate() ? 'Today' : 'Tomorrow') ?> <?= e($u['time']) ?></td>
                            <td><?= e($userNames[$u['user_id']] ?? ('User #' . $u['user_id'])) ?></td>
                            <td class="small"><?= e($u['theme'] ?? 'Chosen when planned') ?></td>
                            <td><?= $badge($u['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$upcoming): ?><tr><td colspan="4" class="text-body-secondary">Nothing scheduled. Enable automation for at least one user.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-12 col-xxl-7">
        <section class="card" id="users" aria-labelledby="users-title">
            <div class="card-header"><h2 class="card-title" id="users-title"><i class="bi bi-people" aria-hidden="true"></i> Users</h2></div>
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
                                <td><div class="fw-semibold"><?= e($u['name']) ?></div><div class="small text-body-secondary"><?= e($u['email']) ?><?= $active ? '' : ' · disabled account' ?></div></td>
                                <?php foreach (['content', 'speech'] as $kind): $k = $a[$kind]; ?>
                                    <td class="small">
                                        <?php if ($a['limit'] === null): ?>
                                            <span class="text-body-secondary">No limit</span> · <?= (int)$k['used'] ?> used
                                        <?php else: ?>
                                            <strong><?= (int)$k['remaining'] ?> of <?= (int)$a['limit'] ?></strong>
                                            <div class="text-body-secondary"><?= (int)$k['manual_used'] ?> manual, <?= (int)$k['auto_used'] ?> automatic<?= $k['reserved'] ? ', ' . (int)$k['reserved'] . ' held' : '' ?><?= $k['failed'] ? ', ' . (int)$k['failed'] . ' failed (not counted)' : '' ?></div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-body border-top">
                    <p class="small text-body-secondary">Enabled users get <?= (int)$limit ?> messages with audio per day and a shared daily limit of <?= (int)$limit ?> content and <?= (int)$limit ?> speech generations (manual + automatic). Turning automation on mid-day catches up on today's earlier times.</p>
                    <button class="btn btn-primary" type="submit"><span class="btn-label">Save users</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                </div>
            </form>
        </section>
    </div>

    <div class="col-12 col-xxl-5">
        <section class="card" id="settings" aria-labelledby="settings-title">
            <div class="card-header"><h2 class="card-title" id="settings-title"><i class="bi bi-sliders" aria-hidden="true"></i> Schedule and content</h2>
                <?php if ($cfg['updated_at']): ?><span class="small text-body-secondary">Saved <?= e(View::dateTime($cfg['updated_at'])) ?></span><?php endif; ?></div>
            <div class="card-body">
                <form method="post" action="<?= url('/admin/automation/settings') ?>" data-once>
                    <?= csrf_field() ?>
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6">Generation times (IST)</legend>
                        <div class="row g-2">
                            <?php for ($i = 0; $i < AutomationConfigRepository::MAX_TIMES; $i++): ?>
                                <div class="col-4"><label class="visually-hidden" for="time<?= $i ?>">Time <?= $i + 1 ?></label>
                                    <input class="form-control" type="time" id="time<?= $i ?>" name="times[]" value="<?= e($cfg['times'][$i] ?? '') ?>"></div>
                            <?php endfor; ?>
                        </div>
                        <div class="form-text">Up to <?= (int)AutomationConfigRepository::MAX_TIMES ?> times. Clear a box to remove that run.</div>
                    </fieldset>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6"><label class="form-label" for="language">Language</label>
                            <input class="form-control" id="language" name="language" value="<?= e($cfg['language']) ?>" maxlength="40" required list="languageList">
                            <datalist id="languageList"><?php foreach (['English', 'Hindi', 'Marathi', 'Tamil', 'Telugu', 'Bengali', 'Gujarati', 'Kannada', 'Spanish', 'French'] as $l): ?><option value="<?= e($l) ?>"><?php endforeach; ?></datalist>
                        </div>
                        <div class="col-sm-6"><label class="form-label" for="voice">Voice</label>
                            <select class="form-select" id="voice" name="voice">
                                <?php foreach ($voices as $v): ?><option value="<?= e($v) ?>" <?= $cfg['voice'] === $v ? 'selected' : '' ?>><?= e(ucfirst($v)) ?></option><?php endforeach; ?>
                            </select></div>
                    </div>
                    <div class="mb-3"><label class="form-label" for="themes">Motivational themes</label>
                        <textarea class="form-control" id="themes" name="themes" rows="8" required><?= e(implode("\n", $cfg['themes'])) ?></textarea>
                        <div class="form-text">One per line. Each run uses a different theme, rotating daily.</div>
                    </div>
                    <button class="btn btn-primary" type="submit"><span class="btn-label">Save settings</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-12">
        <section class="card" id="jobs" aria-labelledby="jobs-title">
            <div class="card-header"><h2 class="card-title" id="jobs-title"><i class="bi bi-list-check" aria-hidden="true"></i> Today's jobs</h2>
                <span class="small text-body-secondary">Message text and audio stay private to each user</span></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">Time</th><th scope="col">User</th><th scope="col">Theme</th><th scope="col">Status</th><th scope="col">Attempts</th><th scope="col">Details</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($todayJobs as $job): ?>
                        <tr>
                            <td class="text-nowrap"><?= e($job['time']) ?></td>
                            <td><?= e($userNames[$job['user_id']] ?? ('User #' . $job['user_id'])) ?></td>
                            <td class="small"><?= e($job['theme']) ?></td>
                            <td><?= $badge($job['status']) ?></td>
                            <td class="small text-nowrap">Text <?= (int)$job['content']['attempts'] ?> · Speech <?= (int)$job['speech']['attempts'] ?></td>
                            <td class="small"><?= e($job['message'] ?? '') ?>
                                <?php if (!empty($job['content']['quote_warning'])): ?><div class="text-warning-emphasis">The text may contain a quotation; review it.</div><?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (in_array($job['status'], ['failed', 'uncertain'], true)): ?>
                                    <form method="post" action="<?= url('/admin/automation/jobs/' . $job['id'] . '/retry') ?>"
                                          <?= $job['status'] === 'uncertain' ? 'data-confirm="Only retry if the Azure usage log shows this request produced nothing. Retrying an uncertain request can create a duplicate charge. Continue?"' : '' ?>>
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Retry</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$todayJobs): ?><tr><td colspan="7" class="text-body-secondary">No jobs planned yet today. Jobs appear after the next cron run.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
<?php endif; ?>
</div>
