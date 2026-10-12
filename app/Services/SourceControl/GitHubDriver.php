<?php

namespace App\Services\SourceControl;

use App\Models\SourceProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GitHubDriver implements SourceControlDriver
{
    public function __construct(protected SourceProvider $provider) {}

    protected function http(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->withToken($this->provider->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20)
            ->throw();
    }

    public function validate(): string
    {
        return $this->http()->get('/user')->json('login');
    }

    public function repositories(): array
    {
        return collect($this->http()->get('/user/repos', ['per_page' => 100, 'sort' => 'updated'])->json())
            ->pluck('full_name')
            ->all();
    }

    public function authorizeKey(string $title, string $publicKey): void
    {
        try {
            $this->http()->post('/user/keys', ['title' => $title, 'key' => trim($publicKey)]);
        } catch (RequestException $e) {
            // 422 = "key is already in use" - which is exactly what we want.
            if ($e->response->status() !== 422) {
                throw $e;
            }
        }
    }

    public function createWebhook(string $repository, string $url): ?string
    {
        return (string) $this->http()->post("/repos/{$repository}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'insecure_ssl' => '0'],
        ])->json('id');
    }

    public function deleteWebhook(string $repository, string $hookId): void
    {
        $this->http()->delete("/repos/{$repository}/hooks/{$hookId}");
    }
}
