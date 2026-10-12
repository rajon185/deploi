<?php

namespace App\Services\SourceControl;

use App\Models\SourceProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GitLabDriver implements SourceControlDriver
{
    public function __construct(protected SourceProvider $provider) {}

    protected function http(): PendingRequest
    {
        $base = rtrim($this->provider->url ?: 'https://gitlab.com', '/').'/api/v4';

        return Http::baseUrl($base)
            ->withToken($this->provider->token)
            ->acceptJson()
            ->timeout(20)
            ->throw();
    }

    public function validate(): string
    {
        return $this->http()->get('/user')->json('username');
    }

    public function repositories(): array
    {
        return collect($this->http()->get('/projects', [
            'membership' => 'true', 'per_page' => 100, 'order_by' => 'last_activity_at', 'simple' => 'true',
        ])->json())->pluck('path_with_namespace')->all();
    }

    public function authorizeKey(string $title, string $publicKey): void
    {
        try {
            $this->http()->post('/user/keys', ['title' => $title, 'key' => trim($publicKey)]);
        } catch (RequestException $e) {
            // 400 "has already been taken" means the key is registered already.
            if (! in_array($e->response->status(), [400, 422], true)) {
                throw $e;
            }
        }
    }

    public function createWebhook(string $repository, string $url): ?string
    {
        return (string) $this->http()->post('/projects/'.rawurlencode($repository).'/hooks', [
            'url' => $url,
            'push_events' => true,
            'enable_ssl_verification' => true,
        ])->json('id');
    }

    public function deleteWebhook(string $repository, string $hookId): void
    {
        $this->http()->delete('/projects/'.rawurlencode($repository).'/hooks/'.$hookId);
    }
}
