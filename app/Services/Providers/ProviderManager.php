<?php

namespace App\Services\Providers;

use InvalidArgumentException;

class ProviderManager
{
    /**
     * Supported cloud providers, and the credential fields each one needs.
     * "custom" (any server you can SSH into as root) is handled without a driver.
     */
    public const PROVIDERS = [
        'digitalocean' => [
            'name' => 'DigitalOcean',
            'driver' => DigitalOceanDriver::class,
            'fields' => ['token' => 'Personal access token (read + write)'],
            'help' => 'Create one at cloud.digitalocean.com → API → Tokens with read & write scope.',
        ],
        'hetzner' => [
            'name' => 'Hetzner Cloud',
            'driver' => HetznerDriver::class,
            'fields' => ['token' => 'Project API token (read & write)'],
            'help' => 'Hetzner Console → your project → Security → API tokens.',
        ],
        'vultr' => [
            'name' => 'Vultr',
            'driver' => VultrDriver::class,
            'fields' => ['token' => 'API key'],
            'help' => 'my.vultr.com → Account → API. Allow-list the IP Deploi runs from.',
        ],
        'linode' => [
            'name' => 'Linode / Akamai',
            'driver' => LinodeDriver::class,
            'fields' => ['token' => 'Personal access token (Linodes read/write)'],
            'help' => 'cloud.linode.com → Profile → API Tokens.',
        ],
    ];

    public static function make(string $provider, array $credentials): ServerProviderDriver
    {
        $class = self::PROVIDERS[$provider]['driver'] ?? throw new InvalidArgumentException("Unknown provider [{$provider}].");

        return new $class($credentials);
    }

    public static function name(string $provider): string
    {
        return $provider === 'custom' ? 'Custom' : (self::PROVIDERS[$provider]['name'] ?? ucfirst($provider));
    }
}
