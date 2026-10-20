<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Repositories\AutomationConfigRepository;
use App\Repositories\AutomationJobRepository;
use App\Repositories\UserRepository;
use App\Services\AutomationRunner;
use App\Services\DailyAllowance;
use App\Services\SpeechGenerator;

final class AutomationController
{
    public function index(): void
    {
        $uid = (int)Auth::id();
        $store = App::store();
        $config = new AutomationConfigRepository($store);
        $allowance = new DailyAllowance($store);
        $jobs = new AutomationJobRepository($store);
        $data = [
            'title'     => 'Automation',
            'enabled'   => $config->isEnabledFor($uid),
            'allowance' => $allowance->status($uid),
            'myJobs'    => $jobs->recentForUser($uid, 7),
            'cfg'       => $config->get(),
            'today'     => ($runner = new AutomationRunner($store))->slotsForUser($uid),
            'scripts'   => ['js/automation.js'],
        ];
        if (Auth::isAdmin()) {
            $users = array_values(App::get(UserRepository::class)->all());
            usort($users, fn($a, $b) => [$a['status'] !== 'active', strtolower($a['name'])] <=> [$b['status'] !== 'active', strtolower($b['name'])]);
            $rows = [];
            foreach ($users as $u) {
                $rows[] = ['user' => $u, 'allowance' => $allowance->status((int)$u['id'])];
            }
            $data += [
                'users'      => $rows,
                'userNames'  => array_column($users, 'name', 'id'),
                'todayJobs'  => $jobs->forDay(Clock::businessDate()),
                'upcoming'   => $runner->upcoming(12),
                'heartbeat'  => $runner->heartbeat(),
                'voices'     => SpeechGenerator::VOICES,
            ];
        }
        Response::view('automation/index', $data);
    }

    /** MP3 for a job — only ever served to the job's owner (admins included). */
    public function audio(string $id): void
    {
        $parsed = AutomationJobRepository::parseId($id);
        if ($parsed === null || $parsed['user_id'] !== (int)Auth::id()) {
            throw new HttpException(404);
        }
        $job = (new AutomationJobRepository(App::store()))->find($id);
        if ($job === null || (int)$job['user_id'] !== (int)Auth::id() || ($job['speech']['state'] ?? '') !== 'success') {
            throw new HttpException(404);
        }
        $path = App::store()->path(AutomationJobRepository::audioRel((int)$job['user_id'], $id));
        if (!is_file($path)) {
            throw new HttpException(404);
        }
        session_write_close();
        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline')
            . '; filename="motivation-' . $job['date'] . '-' . str_replace(':', '', $job['time']) . '.mp3"');
        readfile($path);
    }
}
