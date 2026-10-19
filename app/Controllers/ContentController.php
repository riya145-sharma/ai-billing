<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Ids;
use App\Core\Logger;
use App\Core\Response;
use App\Services\ContentGenerator;
use App\Services\GeneratorException;

final class ContentController
{
    public function index(): void
    {
        $gen = App::get(ContentGenerator::class);
        Response::view('content/index', ['title' => 'Content generator', 'config' => $gen->config(),
            'blocked' => $gen->unavailableReason(), 'history' => $gen->history((int)Auth::id()),
            'requestId' => Ids::uuid(), 'scripts' => ['js/content.js']]);
    }

    public function generate(): void
    {
        $uid = (int)Auth::id();
        session_write_close();
        @set_time_limit(max(60, \App\Core\Settings::int('azure.request_timeout_sec', 120) + 30));
        header('Content-Type: application/json; charset=utf-8');
        try {
            foreach (['prompt', 'format', 'tone', 'request_id'] as $field) {
                if (!is_string($_POST[$field] ?? null)) throw new GeneratorException('form', 'Complete all fields.');
            }
            $record = App::get(ContentGenerator::class)->generate($uid, $_POST['prompt'], $_POST['format'], $_POST['tone'], $_POST['request_id']);
            echo json_encode(['ok' => $record['status'] === 'success', 'record' => $record, 'request_id' => Ids::uuid()]);
        } catch (GeneratorException $e) {
            http_response_code($e->httpStatus);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Logger::error('content generation failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'The request was interrupted. Reload to check history before trying again.']);
        }
    }
}
