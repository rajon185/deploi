<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LinodeDriver implements ServerProviderDriver
{
    public function __construct(protected array $credentials) {}

    protected function http(): PendingRequest
    {
        return Http::baseUrl('https://api.linode.com/v4')
            ->withToken($this->credentials['token'] ?? '')
            ->acceptJson()
            ->timeout(30)
            ->throw();
    }

    public function validate(): void
    {
        $this->http()->get('/profile');
    }

    public function regions(): array
    {
        return Cache::remember($this->cacheKey('regions'), 3600, function () {
            return collect($this->http()->get('/regions')->json('data'))
                ->mapWithKeys(fn ($r) => [$r['id'] => "{$r['label']} ({$r['id']})"])
                ->sort()
                ->all();
        });
    }

    public function sizes(?string $region = null): array
    {
        return Cache::remember($this->cacheKey('sizes'), 3600, function () {
            return collect($this->http()->get('/linode/types', ['page_size' => 500])->json('data'))
                ->sortBy('price.monthly')
                ->mapWithKeys(fn ($t) => [$t['id'] => sprintf(
                    '%s - %d vCPU / %s GB RAM / %d GB disk - $%s/mo',
                    $t['label'], $t['vcpus'], round($t['memory'] / 1024, 1), $t['disk'] / 1024, $t['price']['monthly'],
                )])
                ->all();
        });
    }

    public function createServer(string $name, string $region, string $size, string $publicKey): array
    {
        // Linode labels: 3-64 chars, letters, digits, dashes, underscores, dots.
        $label = Str::limit(preg_replace('/[^A-Za-z0-9_.-]/', '-', $name), 60, '');

        $linode = $this->http()->post('/linode/instances', [
            'region' => $region,
            'type' => $size,
            'image' => 'linode/ubuntu24.04',
            'label' => strlen($label) < 3 ? 'deploi-'.$label : $label,
            'root_pass' => Str::password(32),
            'authorized_keys' => [trim($publicKey)],
            'booted' => true,
            'private_ip' => true,
            'tags' => ['deploi'],
        ])->json();

        return ['id' => (string) $linode['id'], 'ssh_key_id' => null];
    }

    public function getServer(string $id): array
    {
        $linode = $this->http()->get("/linode/instances/{$id}")->json();
        $ips = collect($linode['ipv4'] ?? []);
        $isPrivate = fn ($ip) => str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.');

        return [
            'status' => $linode['status'] === 'running' ? 'active' : 'creating',
            'ip' => $ips->reject($isPrivate)->first(),
            'private_ip' => $ips->filter($isPrivate)->first(),
        ];
    }

    public function listServers(): array
    {
        $isPrivate = fn ($ip) => str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.');

        return collect($this->http()->get('/linode/instances', ['page_size' => 500])->json('data'))
            ->map(fn ($l) => [
                'id' => (string) $l['id'],
                'name' => $l['label'],
                'ip' => collect($l['ipv4'] ?? [])->reject($isPrivate)->first(),
                'region' => $l['region'] ?? null,
                'size' => $l['type'] ?? null,
                'status' => $l['status'],
            ])->all();
    }

    public function rebootServer(string $id): void
    {
        $this->http()->post("/linode/instances/{$id}/reboot");
    }

    public function deleteServer(string $id): void
    {
        $this->http()->delete("/linode/instances/{$id}");
    }

    public function deleteSshKey(string $id): void
    {
        // Keys are passed inline at creation on Linode; nothing to clean up.
    }

    protected function cacheKey(string $what): string
    {
        return 'linode:'.$what.':'.md5($this->credentials['token'] ?? '');
    }
}
