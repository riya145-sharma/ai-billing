<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

final class DashboardController
{
    public function index(): void
    {
        Response::view('dashboard/index', ['title' => 'Dashboard']);
    }
}
