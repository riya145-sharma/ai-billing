<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Ids;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Url;
use App\Services\GeneratorException;
use App\Services\SpeechGenerator;

final class SpeechController
{
    public function index(): void
    {
        $gen = App::get(SpeechGenerator::class);
        Response::view('speech/index', ['title' => 'Text to speech', 'config' => $gen->config(),
            'blocked' => $gen->unavailableReason(), 'history' => $gen->history((int)Auth::id()),
            'requestId' => Ids::uuid(), 'scripts' => ['js/speech.js']]);
    }

    public function generate(): void
    {
        $uid = (int)Auth::id();
        session_write_close();
        ignore_user_abort(true);
        @set_time_limit(max(60, \App\Core\Settings::int('azure.request_timeout_sec', 120) + 30));
        header('Content-Type: application/json; charset=utf-8');
        try {
            foreach (['input', 'voice', 'request_id'] as $field) {
                if (!is_string($_POST[$field] ?? null)) throw new GeneratorException('form', 'Complete all fields.');
            }
            $r = App::get(SpeechGenerator::class)->generate($uid, $_POST['input'], $_POST['voice'], $_POST['request_id']);
            echo json_encode(['ok' => $r['status'] === 'success', 'record' => $r, 'request_id' => Ids::uuid(),
                'audio_url' => $r['status'] === 'success' ? Url::to('/speech/audio/' . $r['id']) : null,
                'download_url' => $r['status'] === 'success' ? Url::to('/speech/audio/' . $r['id'], ['download' => '1']) : null]);
        } catch (GeneratorException $e) {
            http_response_code($e->httpStatus);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Logger::error('speech generation failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'The request was interrupted. Reload to check history before trying again.']);
        }
    }

    public function audio(string $id): void
    {
        if (!ctype_digit($id)) throw new HttpException(404);
        // Lookup is scoped to the signed-in user's collection, including for admins.
        $path = App::get(SpeechGenerator::class)->audioPath((int)Auth::id(), (int)$id);
        if ($path === null) throw new HttpException(404);
        session_write_close();
        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="speech-' . (int)$id . '.mp3"');
        readfile($path);
    }
}
