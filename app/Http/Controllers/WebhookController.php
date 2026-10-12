<?php

namespace App\Http\Controllers;

use App\Actions\DeploySite;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * POST /deploy/{token} - triggered by GitHub/GitLab/Bitbucket push hooks, CI pipelines or curl.
 * Push payloads for a different branch are ignored.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        $site = Site::where('deploy_webhook_token', $token)->first();

        if (! $site || $site->repository_status !== 'installed') {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        $branch = $this->pushedBranch($request);

        if ($branch !== null && $branch !== $site->branch) {
            return response()->json(['message' => "Ignored push to {$branch}; site deploys {$site->branch}."]);
        }

        $deployment = DeploySite::run($site, 'webhook');

        return response()->json(['message' => 'Deployment queued.', 'deployment_id' => $deployment->id]);
    }

    protected function pushedBranch(Request $request): ?string
    {
        // GitHub & GitLab: "ref": "refs/heads/main"
        if ($ref = $request->input('ref')) {
            return str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : '__not-a-branch__';
        }

        // Bitbucket: push.changes[].new.name
        if ($name = data_get($request->input('push.changes'), '0.new.name')) {
            return $name;
        }

        return null;
    }
}
