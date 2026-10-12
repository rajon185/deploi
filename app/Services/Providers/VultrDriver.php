<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class VultrDriver implements ServerProviderDriver
{
    public function __construct(protected array $credentials) {}

    protected function http(): PendingRequest
    {
        return Http::baseUrl('https://api.vultr.com/v2')
            ->withToken($this->credentials['token'] ?? '')
            ->acceptJson()
            ->timeout(30)
            ->throw();
    }

    public function validate(): void
    {
        $this->http()->get('/account');
    }

    public function regions(): array
    {
        return Cache::remember($this->cacheKey('regions'), 3600, function () {
            return collect($this->http()->get('/regions', ['per_page' => 500])->json('regions'))
                ->mapWithKeys(fn ($r) => [$r['id'] => "{$r['city']}, {$r['country']} ({$r['id']})"])
                ->sort()
                ->all();
        });
    }

    public function sizes(?string $region = null): array
    {
        $plans = Cache::remember($this->cacheKey('sizes'), 3600, function () {
            return $this->http()->get('/plans', ['type' => 'all', 'per_page' => 500])->json('plans');
        });

        return collect($plans)
            ->when($region, fn ($c) => $c->filter(fn ($p) => in_array($region, $p['locations'] ?? [], true)))
            ->sortBy('monthly_cost')
            ->mapWithKeys(fn ($p) => [$p['id'] => sprintf(
                '%s - %d vCPU / %s GB RAM / %d GB disk - $%s/mo',
                $p['id'], $p['vcpu_count'], round($p['ram'] / 1024, 1), $p['disk'], $p['monthly_cost'],
            )])
            ->all();
    }

    protected function ubuntuOsId(): int
    {
        return Cache::remember('vultr:ubuntu-os', 86400, function () {
            $os = collect($this->http()->get('/os', ['per_page' => 500])->json('os'))
                ->first(fn ($o) => str_starts_with($o['name'], 'Ubuntu 24.04') && str_contains($o['name'], 'x64'));

            return $os['id'] ?? 2284;
        });
    }

    public function createServer(string $name, string $region, string $size, string $publicKey): array
    {
        $key = $this->http()->post('/ssh-keys', [
            'name' => 'deploi-'.Str::slug($name).'-'.Str::lower(Str::random(6)),
            'ssh_key' => $publicKey,
        ])->json('ssh_key');

        $instance = $this->http()->post('/instances', [
            'region' => $region,
            'plan' => $size,
            'os_id' => $this->ubuntuOsId(),
            'label' => $name,
            'hostname' => Str::slug($name),
            'sshkey_id' => [$key['id']],
            'enable_ipv6' => true,
            'tags' => ['deploi'],
        ])->json('instance');

        return ['id' => (string) $instance['id'], 'ssh_key_id' => (string) $key['id']];
    }

    public function getServer(string $id): array
    {
        $instance = $this->http()->get("/instances/{$id}")->json('instance');
        $ready = $instance['status'] === 'active' && ($instance['server_status'] ?? 'ok') === 'ok';
        $ip = $instance['main_ip'] ?? null;

        return [
            'status' => $ready ? 'active' : 'creating',
            'ip' => $ip && $ip !== '0.0.0.0' ? $ip : null,
            'private_ip' => ($instance['internal_ip'] ?? null) ?: null,
        ];
    }

    public function listServers(): array
    {
        return collect($this->http()->get('/instances', ['per_page' => 500])->json('instances'))
            ->map(fn ($i) => [
                'id' => (string) $i['id'],
                'name' => $i['label'] ?: $i['hostname'] ?? $i['id'],
                'ip' => ($i['main_ip'] ?? '0.0.0.0') !== '0.0.0.0' ? $i['main_ip'] : null,
                'region' => $i['region'] ?? null,
                'size' => $i['plan'] ?? null,
                'status' => $i['status'],
            ])->all();
    }

    public function rebootServer(string $id): void
    {
        $this->http()->post("/instances/{$id}/reboot");
    }

    public function deleteServer(string $id): void
    {
        $this->http()->delete("/instances/{$id}");
    }

    public function deleteSshKey(string $id): void
    {
        $this->http()->delete("/ssh-keys/{$id}");
    }

    protected function cacheKey(string $what): string
    {
        return 'vultr:'.$what.':'.md5($this->credentials['token'] ?? '');
    }
}
