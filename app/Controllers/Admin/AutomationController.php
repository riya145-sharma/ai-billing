<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Repositories\AuditLog;
use App\Repositories\AutomationConfigRepository;
use App\Repositories\UserRepository;
use App\Services\AutomationRunner;

final class AutomationController
{
    use AdminHelpers;

    public function settings(): void
    {
        $times = is_array($_POST['times'] ?? null) ? array_map(fn($t) => is_scalar($t) ? (string)$t : '', $_POST['times']) : [];
        $themes = preg_split('/\R/u', self::post('themes')) ?: [];
        try {
            $cfg = (new AutomationConfigRepository(App::store()))->saveSettings($times, self::post('language'), self::post('voice'), $themes, Auth::id(),
                ctype_digit(self::post('interval_minutes')) ? (int)self::post('interval_minutes') : -1);
        } catch (\InvalidArgumentException $e) {
            self::failBack($e->getMessage(), '/automation#settings');
        }
        App::get(AuditLog::class)->record('automation.settings_updated', Auth::id(), 'automation', null, [
            'times' => $cfg['times'], 'interval_minutes' => $cfg['interval_minutes'], 'language' => $cfg['language'], 'voice' => $cfg['voice'], 'themes' => count($cfg['themes']),
        ]);
        self::ok('Automation settings saved: messages ' . AutomationConfigRepository::describe($cfg)
            . ($cfg['interval_minutes'] > 0 ? ', next at ' . AutomationConfigRepository::nextIntervalSlot($cfg, Clock::now())['time'] . ' IST' : '')
            . '. Jobs that have not started yet use the new settings.', '/automation#settings');
    }

    public function users(): void
    {
        $ids = is_array($_POST['user_ids'] ?? null) ? $_POST['user_ids'] : [];
        $repo = App::get(UserRepository::class);
        $valid = [];
        foreach ($ids as $id) {
            if (is_string($id) && ctype_digit($id) && ($u = $repo->find((int)$id)) !== null && $u['status'] === 'active') {
                $valid[] = (int)$id;
            }
        }
        $cfg = (new AutomationConfigRepository(App::store()))->setEnabledUsers($valid, Auth::id());
        App::get(AuditLog::class)->record('automation.users_updated', Auth::id(), 'automation', null, ['user_ids' => array_keys($cfg['users'])]);
        self::ok(count($valid) === 0
            ? 'Automation is off for every user.'
            : 'Automation is on for ' . count($valid) . ' user(s). Their manual and automatic generations now share the daily limit of '
                . (new AutomationConfigRepository(App::store()))->dailyLimit() . '.', '/automation#users');
    }

    public function retry(string $id): void
    {
        try {
            (new AutomationRunner(App::store()))->retry($id, Auth::id());
        } catch (\InvalidArgumentException $e) {
            self::failBack($e->getMessage(), '/automation#jobs');
        }
        self::ok('Retry queued. It runs on the next cron run (within 5 minutes).', '/automation#jobs');
    }
}
