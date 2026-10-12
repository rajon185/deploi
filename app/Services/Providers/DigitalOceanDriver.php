<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DigitalOceanDriver implements ServerProviderDriver
{
    public function __construct(protected array $credentials) {}

    protected function http(): PendingRequest
    {
        return Http::baseUrl('https://api.digitalocean.com/v2')
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
            return collect($this->http()->get('/regions', ['per_page' => 200])->json('regions'))
                ->where('available', true)
                ->mapWithKeys(fn ($r) => [$r['slug'] => "{$r['name']} ({$r['slug']})"])
                ->sort()
                ->all();
        });
    }

    public function sizes(?string $region = null): array
    {
        $sizes = Cache::remember($this->cacheKey('sizes'), 3600, function () {
            return $this->http()->get('/sizes', ['per_page' => 200])->json('sizes');
        });

        return collect($sizes)
            ->where('available', true)
            ->when($region, fn ($c) => $c->filter(fn ($s) => in_array($region, $s['regions'], true)))
            ->sortBy('price_monthly')
            ->mapWithKeys(fn ($s) => [$s['slug'] => sprintf(
                '%s - %d vCPU / %s RAM / %d GB disk - $%s/mo',
                $s['slug'], $s['vcpus'], $s['memory'] >= 1024 ? ($s['memory'] / 1024).' GB' : $s['memory'].' MB',
                $s['disk'], rtrim(rtrim(number_format($s['price_monthly'], 2), '0'), '.'),
            )])
            ->all();
    }

    public function createServer(string $name, string $region, string $size, string $publicKey): array
    {
        $key = $this->http()->post('/account/keys', [
            'name' => 'deploi-'.Str::slug($name).'-'.Str::lower(Str::random(6)),
            'public_key' => $publicKey,
        ])->json('ssh_key');

        $droplet = $this->http()->post('/droplets', [
            'name' => Str::slug($name),
            'region' => $region,
            'size' => $size,
            'image' => 'ubuntu-24-04-x64',
            'ssh_keys' => [$key['id']],
            'ipv6' => true,
            'monitoring' => true,
            'tags' => ['deploi'],
        ])->json('droplet');

        return ['id' => (string) $droplet['id'], 'ssh_key_id' => (string) $key['id']];
    }

    public function getServer(string $id): array
    {
        $droplet = $this->http()->get("/droplets/{$id}")->json('droplet');
        $v4 = collect($droplet['networks']['v4'] ?? []);

        return [
            'status' => $droplet['status'] === 'active' ? 'active' : 'creating',
            'ip' => $v4->firstWhere('type', 'public')['ip_address'] ?? null,
            'private_ip' => $v4->firstWhere('type', 'private')['ip_address'] ?? null,
        ];
    }

    public function listServers(): array
    {
        return collect($this->http()->get('/droplets', ['per_page' => 200])->json('droplets'))
            ->map(fn ($d) => [
                'id' => (string) $d['id'],
                'name' => $d['name'],
                'ip' => collect($d['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? null,
                'region' => $d['region']['slug'] ?? null,
                'size' => $d['size_slug'] ?? null,
                'status' => $d['status'],
            ])->all();
    }

    public function rebootServer(string $id): void
    {
        $this->http()->post("/droplets/{$id}/actions", ['type' => 'reboot']);
    }

    public function deleteServer(string $id): void
    {
        $this->http()->delete("/droplets/{$id}");
    }

    public function deleteSshKey(string $id): void
    {
        $this->http()->delete("/account/keys/{$id}");
    }

    protected function cacheKey(string $what): string
    {
        return 'do:'.$what.':'.md5($this->credentials['token'] ?? '');
    }
}
