<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\AuditLog;
use App\Repositories\AzureConfigRepository;
use App\Services\AzureClient;
use App\Storage\DuplicateException;

final class AzureController
{
    private const FIELDS = [
        'name', 'purpose', 'endpoint_base', 'api_style', 'api_version', 'api_key_env', 'deployment_name', 'model_name',
        'model_version', 'deployment_type', 'tpm_limit', 'rpm_limit', 'lifecycle_status', 'retirement_date',
    ];

    public function index(): void
    {
        $repo = App::get(AzureConfigRepository::class);
        $all = array_values(array_filter($repo->all(), fn($row) => in_array($row['purpose'], ['chat', 'speech'], true)));
        usort($all, fn($a, $b) => [$a['purpose'], !$a['is_default']] <=> [$b['purpose'], !$b['is_default']]);
        $editId = (int)($_GET['edit'] ?? 0);
        $editing = $editId > 0 ? $repo->find($editId) : null;
        if ($editing !== null && !in_array($editing['purpose'], ['chat', 'speech'], true)) throw new HttpException(404);
        $chat = $repo->defaultFor('chat');

        $keys = [];
        foreach ($all as $c) {
            $keys[$c['id']] = AzureClient::keyFor($c) !== null;
        }

        Response::view('admin/azure', [
            'title'       => 'Azure deployments',
            'deployments' => $all,
            'keys'        => $keys,
            'editing'     => $editing,
            'discovered'  => Session::get('azure_discovered'),
            // New deployments usually live on the same resource as the chat one.
            'defaults'    => [
                'name' => '', 'purpose' => 'chat', 'endpoint_base' => $chat['endpoint_base'] ?? '', 'api_style' => 'v1',
                'api_version' => '', 'api_key_env' => $chat['api_key_env'] ?? 'AZURE_OPENAI_API_KEY', 'deployment_name' => '',
                'model_name' => 'gpt-4.1-mini', 'model_version' => '', 'deployment_type' => 'GlobalStandard', 'tpm_limit' => '',
                'rpm_limit' => '', 'lifecycle_status' => '', 'retirement_date' => '', 'is_default' => true,
            ],
        ]);
    }

    public function store(): void
    {
        $data = self::input();
        $data['is_default'] = !empty($_POST['is_default']);
        $data['is_active'] = true;
        try {
            $row = App::get(AzureConfigRepository::class)->create($data);
        } catch (\InvalidArgumentException | DuplicateException $e) {
            self::fail($e, '/admin/azure');
        }
        App::get(AuditLog::class)->record('admin.azure_created', Auth::id(), 'azure_config', (string)$row['id'], ['deployment' => $row['deployment_name'], 'purpose' => $row['purpose']]);
        Session::flash('success', "Deployment “{$row['deployment_name']}” added. Use “Test connection” to check the endpoint and key.");
        Response::redirect('/admin/azure');
    }

    public function update(string $id): void
    {
        $id = self::id($id);
        $data = self::input();
        $data['is_default'] = !empty($_POST['is_default']);
        try {
            $row = App::get(AzureConfigRepository::class)->update($id, $data + ['verification_status' => 'unverified']);
        } catch (\InvalidArgumentException | DuplicateException $e) {
            self::fail($e, '/admin/azure?edit=' . $id);
        }
        App::get(AuditLog::class)->record('admin.azure_updated', Auth::id(), 'azure_config', (string)$id, ['deployment' => $row['deployment_name']]);
        Session::flash('success', 'Deployment saved.');
        Response::redirect('/admin/azure');
    }

    public function makeDefault(string $id): void
    {
        $id = self::id($id);
        try {
            $row = App::get(AzureConfigRepository::class)->setDefault($id);
        } catch (\InvalidArgumentException $e) {
            self::fail($e, '/admin/azure?edit=' . $id);
        }
        App::get(AuditLog::class)->record('admin.azure_default', Auth::id(), 'azure_config', (string)$id);
        Session::flash('success', "“{$row['deployment_name']}” is now the default {$row['purpose']} deployment.");
        Response::redirect('/admin/azure');
    }

    public function toggle(string $id): void
    {
        $id = self::id($id);
        $repo = App::get(AzureConfigRepository::class);
        $row = $repo->find($id) ?? throw new HttpException(404);
        $active = empty($row['is_active']);
        $repo->setActive($id, $active);
        App::get(AuditLog::class)->record($active ? 'admin.azure_enabled' : 'admin.azure_disabled', Auth::id(), 'azure_config', (string)$id);
        Session::flash('success', "“{$row['deployment_name']}” " . ($active ? 'enabled.' : 'disabled.'));
        Response::redirect('/admin/azure');
    }

    public function verify(string $id): void
    {
        $id = self::id($id);
        $repo = App::get(AzureConfigRepository::class);
        $row = $repo->find($id) ?? throw new HttpException(404);
        $client = new AzureClient(App::store());
        $r = $client->verify($row);
        // Key and endpoint work — now check the deployment itself exists and fits its purpose.
        if ($r['ok']) {
            $list = $client->listDeployments($row);
            if ($list['ok']) {
                $match = null;
                foreach ($list['items'] as $d) {
                    if (strcasecmp($d['deployment_name'], $row['deployment_name']) === 0) {
                        $match = $d;
                    }
                }
                $names = implode(', ', array_map(fn($d) => "{$d['deployment_name']} ({$d['model_name']})", $list['items'])) ?: 'none';
                if ($match === null) {
                    $r = ['ok' => false, 'http_status' => 0, 'latency_ms' => $r['latency_ms'],
                          'error' => "No deployment named “{$row['deployment_name']}” exists on this Azure resource. Deployments found: {$names}."];
                } elseif ($row['purpose'] === 'speech' && !AzureClient::isSpeechModel($match['model_name'])) {
                    $r = ['ok' => false, 'http_status' => 0, 'latency_ms' => $r['latency_ms'],
                          'error' => 'This deployment does not run a supported TTS model. Choose a speech model such as gpt-4o-mini-tts or tts-1.'];
                } elseif ($row['purpose'] === 'chat' && (AzureClient::isSpeechModel($match['model_name']) || AzureClient::isImageModel($match['model_name']))) {
                    $r = ['ok' => false, 'http_status' => 0, 'latency_ms' => $r['latency_ms'],
                          'error' => 'Content generation needs a chat model such as gpt-4.1-mini.'];
                }
            }
        }
        $repo->recordVerification($id, $r['ok'], ['checked' => 'models_list+deployment', 'latency_ms' => $r['latency_ms']], $r['ok'] ? null : $r['error']);
        App::get(AuditLog::class)->record('admin.azure_verified', Auth::id(), 'azure_config', (string)$id, ['ok' => $r['ok'], 'http' => $r['http_status']]);
        if ($r['ok']) {
            Session::flash('success', "Connected to Azure in {$r['latency_ms']} ms — the endpoint, key and deployment “{$row['deployment_name']}” all check out. (This check is free; it doesn’t generate anything.)");
        } else {
            Session::flash('danger', 'Connection failed' . ($r['http_status'] ? " (HTTP {$r['http_status']})" : '') . ': ' . ($r['error'] ?? 'unknown error'));
        }
        Response::redirect('/admin/azure');
    }

    /** Ask Azure which deployments exist on the configured resource(s) and offer to add the missing ones. */
    public function discover(): void
    {
        $repo = App::get(AzureConfigRepository::class);
        $client = new AzureClient(App::store());
        $known = [];
        $byEndpoint = [];
        foreach ($repo->all() as $c) {
            $known[strtolower(rtrim($c['endpoint_base'], '/') . '|' . $c['deployment_name'])] = true;
            $byEndpoint[strtolower(rtrim($c['endpoint_base'], '/'))] ??= $c;
        }
        $found = [];
        $errors = [];
        foreach ($byEndpoint as $cfg) {
            $r = $client->listDeployments($cfg);
            if (!$r['ok']) {
                $errors[] = parse_url($cfg['endpoint_base'], PHP_URL_HOST) . ': ' . $r['error'];
                continue;
            }
            foreach ($r['items'] as $d) {
                if (AzureClient::isImageModel($d['model_name'])) continue;
                $found[] = $d + [
                    'endpoint_base' => $cfg['endpoint_base'],
                    'api_key_env'   => $cfg['api_key_env'],
                    'purpose'       => AzureClient::isSpeechModel($d['model_name']) ? 'speech' : 'chat',
                    'configured'    => isset($known[strtolower(rtrim($cfg['endpoint_base'], '/') . '|' . $d['deployment_name'])]),
                ];
            }
        }
        Session::set('azure_discovered', ['at' => time(), 'items' => $found, 'errors' => $errors]);
        App::get(AuditLog::class)->record('admin.azure_discovered', Auth::id(), 'azure_config', null, ['found' => count($found)]);
        Response::redirect('/admin/azure');
    }

    private static function input(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = trim((string)($_POST[$f] ?? ''));
        }
        if (!in_array($out['purpose'], ['chat', 'speech'], true)) $out['purpose'] = 'chat';
        return $out;
    }

    private static function id(string $id): int
    {
        if (!ctype_digit($id)) {
            throw new HttpException(404);
        }
        return (int)$id;
    }

    private static function fail(\Throwable $e, string $back): never
    {
        Session::flash('danger', $e instanceof DuplicateException ? 'That deployment is already configured on this endpoint.' : $e->getMessage());
        Session::flashInput(self::input() + ['is_default' => !empty($_POST['is_default']) ? '1' : '']);
        Response::redirect($back);
    }
}
