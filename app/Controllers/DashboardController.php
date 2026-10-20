<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Response;
use App\Repositories\AutomationConfigRepository;
use App\Repositories\AutomationJobRepository;
use App\Services\AutomationRunner;
use App\Services\DailyAllowance;

final class DashboardController
{
    public function index(): void
    {
        $uid = (int)Auth::id();
        $store = App::store();
        $config = new AutomationConfigRepository($store);
        $runner = new AutomationRunner($store);
        $recent = (new AutomationJobRepository($store))->recentForUser($uid, 7);
        $latest = null;
        foreach ($recent as $job) {
            if (($job['content']['state'] ?? '') === 'success') {
                $latest = $job;
                break;
            }
        }
        $data = [
            'title'     => 'Dashboard',
            'enabled'   => $config->isEnabledFor($uid),
            'allowance' => (new DailyAllowance($store))->status($uid),
            'cfg'       => $config->get(),
            'today'     => $runner->slotsForUser($uid),
            'latest'    => $latest,
        ];
        if (Auth::isAdmin()) {
            $todayJobs = (new AutomationJobRepository($store))->forDay(Clock::businessDate());
            $data += [
                'heartbeat' => $runner->heartbeat(),
                'jobCounts' => array_count_values(array_column($todayJobs, 'status')),
                'jobTotal'  => count($todayJobs),
            ];
        }
        Response::view('dashboard/index', $data);
    }
}
