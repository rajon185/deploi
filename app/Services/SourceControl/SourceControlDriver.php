<?php

namespace App\Services\SourceControl;

interface SourceControlDriver
{
    /** Validate the token and return the account's username. */
    public function validate(): string;

    /** @return list<string> "owner/repo" names the account can access. */
    public function repositories(): array;

    /**
     * Add a server's public key to the account so the server can clone any repo the account can.
     * Must be idempotent (a key that's already registered is fine).
     */
    public function authorizeKey(string $title, string $publicKey): void;

    /** Create a push webhook pointing at $url. Returns the provider's hook id. */
    public function createWebhook(string $repository, string $url): ?string;

    public function deleteWebhook(string $repository, string $hookId): void;
}
