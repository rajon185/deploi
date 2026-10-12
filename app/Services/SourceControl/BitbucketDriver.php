<?php

namespace App\Services\SourceControl;

use App\Models\SourceProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class BitbucketDriver implements SourceControlDriver
{
    public function __construct(protected SourceProvider $provider) {}

    protected function http(): PendingRequest
    {
        return Http::baseUrl('https://api.bitbucket.org/2.0')
            ->withBasicAuth($this->provider->username ?? '', $this->provider->token)
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
        return collect($this->http()->get('/repositories', ['role' => 'member', 'pagelen' => 100, 'sort' => '-updated_on'])->json('values'))
            ->pluck('full_name')
            ->all();
    }

    public function authorizeKey(string $title, string $publicKey): void
    {
        try {
            $this->http()->post('/users/'.$this->provider->username.'/ssh-keys', [
                'label' => $title,
                'key' => trim($publicKey),
            ]);
        } catch (RequestException $e) {
            if (! in_array($e->response->status(), [400, 409], true)) {
                throw $e;
            }
        }
    }

    public function createWebhook(string $repository, string $url): ?string
    {
        return $this->http()->post("/repositories/{$repository}/hooks", [
            'description' => 'Deploi auto-deploy',
            'url' => $url,
            'active' => true,
            'events' => ['repo:push'],
        ])->json('uuid');
    }

    public function deleteWebhook(string $repository, string $hookId): void
    {
        $this->http()->delete("/repositories/{$repository}/hooks/".rawurlencode($hookId));
    }
}
