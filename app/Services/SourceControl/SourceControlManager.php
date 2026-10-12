<?php

namespace App\Services\SourceControl;

use App\Models\SourceProvider;
use InvalidArgumentException;

class SourceControlManager
{
    public const PROVIDERS = [
        'github' => [
            'name' => 'GitHub',
            'help' => 'Classic personal access token with "repo" and "admin:public_key" scopes (github.com/settings/tokens).',
        ],
        'gitlab' => [
            'name' => 'GitLab',
            'help' => 'Personal access token with the "api" scope. For self-hosted GitLab also fill in the base URL.',
        ],
        'bitbucket' => [
            'name' => 'Bitbucket',
            'help' => 'Your Bitbucket username plus an app password with Account (write), Repositories (read) and Webhooks (read & write).',
        ],
    ];

    public static function make(SourceProvider $provider): SourceControlDriver
    {
        return match ($provider->provider) {
            'github' => new GitHubDriver($provider),
            'gitlab' => new GitLabDriver($provider),
            'bitbucket' => new BitbucketDriver($provider),
            default => throw new InvalidArgumentException("Unknown source provider [{$provider->provider}]."),
        };
    }
}
