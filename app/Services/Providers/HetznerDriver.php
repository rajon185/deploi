<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HetznerDriver implements ServerProviderDriver
{
    public function __construct(protected array $credentials) {}

    protected function http(): PendingRequest
    {
        return Http::baseUrl('https://api.hetzner.cloud/v1')
            ->withToken($this->credentials['token'] ?? '')
            ->acceptJson()
            ->timeout(30)
            ->throw();
    }

    public function validate(): void
    {
        $this->http()->get('/locations');
    }

    public function regions(): array
    {
        return Cache::remember($this->cacheKey('regions'), 3600, function () {
            return collect($this->http()->get('/locations')->json('locations'))
                ->mapWithKeys(fn ($l) => [$l['name'] => "{$l['city']}, {$l['country']} ({$l['name']})"])
                ->all();
        });
    }

    public function sizes(?string $region = null): array
    {
        $types = Cache::remember($this->cacheKey('sizes'), 3600, function () {
            return $this->http()->get('/server_types', ['per_page' => 50])->json('server_types');
        });

        return collect($types)
            ->reject(fn ($t) => $t['deprecated'] ?? false)
            ->map(function ($t) use ($region) {
                $price = collect($t['prices'])->when($region, fn ($p) => $p->where('location', $region))->first();
                $t['monthly'] = $price ? (float) $price['price_monthly']['gross'] : null;

                return $t;
            })
            ->filter(fn ($t) => $t['monthly'] !== null)
            ->sortBy('monthly')
            ->mapWithKeys(fn ($t) => [$t['name'] => sprintf(
                '%s - %d vCPU / %s GB RAM / %d GB disk - €%.2f/mo',
                $t['name'], $t['cores'], $t['memory'], $t['disk'], $t['monthly'],
            )])
            ->all();
    }

    public function createServer(string $name, string $region, string $size, string $publicKey): array
    {
        $key = $this->http()->post('/ssh_keys', [
            'name' => 'deploi-'.Str::slug($name).'-'.Str::lower(Str::random(6)),
            'public_key' => $publicKey,
        ])->json('ssh_key');

        $server = $this->http()->post('/servers', [
            'name' => Str::slug($name),
            'server_type' => $size,
            'location' => $region,
            'image' => 'ubuntu-24.04',
            'ssh_keys' => [$key['id']],
            'labels' => ['managed-by' => 'deploi'],
        ])->json('server');

        return ['id' => (string) $server['id'], 'ssh_key_id' => (string) $key['id']];
    }

    public function getServer(string $id): array
    {
        $server = $this->http()->get("/servers/{$id}")->json('server');

        return [
            'status' => $server['status'] === 'running' ? 'active' : 'creating',
            'ip' => $server['public_net']['ipv4']['ip'] ?? null,
            'private_ip' => $server['private_net'][0]['ip'] ?? null,
        ];
    }

    public function listServers(): array
    {
        return collect($this->http()->get('/servers', ['per_page' => 50])->json('servers'))
            ->map(fn ($s) => [
                'id' => (string) $s['id'],
                'name' => $s['name'],
                'ip' => $s['public_net']['ipv4']['ip'] ?? null,
                'region' => $s['datacenter']['location']['name'] ?? null,
                'size' => $s['server_type']['name'] ?? null,
                'status' => $s['status'],
            ])->all();
    }

    public function rebootServer(string $id): void
    {
        $this->http()->post("/servers/{$id}/actions/reboot");
    }

    public function deleteServer(string $id): void
    {
        $this->http()->delete("/servers/{$id}");
    }

    public function deleteSshKey(string $id): void
    {
        $this->http()->delete("/ssh_keys/{$id}");
    }

    protected function cacheKey(string $what): string
    {
        return 'hetzner:'.$what.':'.md5($this->credentials['token'] ?? '');
    }
}
