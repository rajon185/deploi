<?php

namespace App\Services\Providers;

interface ServerProviderDriver
{
    /** Throw if the credentials are invalid. */
    public function validate(): void;

    /** @return array<string, string> region id => label */
    public function regions(): array;

    /** @return array<string, string> size/plan id => label */
    public function sizes(?string $region = null): array;

    /**
     * Create a fresh Ubuntu 24.04 machine with $publicKey authorised for root.
     *
     * @return array{id: string, ssh_key_id: ?string}
     */
    public function createServer(string $name, string $region, string $size, string $publicKey): array;

    /** @return array{status: string, ip: ?string, private_ip: ?string} status is "creating" or "active" */
    public function getServer(string $id): array;

    /**
     * Machines that already exist in the account.
     *
     * @return list<array{id: string, name: string, ip: ?string, region: ?string, size: ?string, status: string}>
     */
    public function listServers(): array;

    public function rebootServer(string $id): void;

    public function deleteServer(string $id): void;

    public function deleteSshKey(string $id): void;
}
