<?php

namespace App\Http\Controllers\Api;

use App\Actions\DeploySite;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\Request;

/** A small REST API (Sanctum bearer tokens) for CI pipelines and scripts. */
class ApiController extends Controller
{
    protected const SERVER_FIELDS = ['id', 'name', 'type', 'provider', 'ip_address', 'private_ip_address', 'region', 'size', 'php_version', 'database_type', 'status', 'created_at'];

    protected const SITE_FIELDS = ['id', 'server_id', 'domain', 'aliases', 'project_type', 'php_version', 'repository', 'branch', 'zero_downtime', 'status', 'repository_status', 'ssl_status', 'last_deployed_at'];

    public function servers(Request $request)
    {
        return ['data' => $request->user()->servers()->get()->map->only(self::SERVER_FIELDS)];
    }

    public function server(Server $server)
    {
        $this->authorize('manage', $server);

        return ['data' => $server->only(self::SERVER_FIELDS) + ['sites' => $server->sites->map->only(self::SITE_FIELDS)]];
    }

    public function sites(Request $request)
    {
        return ['data' => $request->user()->sites()->get()->map->only(self::SITE_FIELDS)];
    }

    public function site(Site $site)
    {
        $this->authorize('manage', $site);

        return ['data' => $site->only(self::SITE_FIELDS)];
    }

    public function deploy(Site $site)
    {
        $this->authorize('manage', $site);
        abort_unless($site->repository_status === 'installed', 422, 'Site has no installed repository.');

        $deployment = DeploySite::run($site, 'api');

        return response()->json(['data' => $deployment->only(['id', 'status', 'created_at'])], 202);
    }

    public function deployments(Site $site)
    {
        $this->authorize('manage', $site);

        return ['data' => $site->deployments()->latest()->limit(25)->get()
            ->map->only(['id', 'status', 'triggered_by', 'commit_hash', 'commit_message', 'commit_author', 'started_at', 'finished_at'])];
    }

    public function deployment(Site $site, int $deployment)
    {
        $this->authorize('manage', $site);
        $deployment = $site->deployments()->with('task')->findOrFail($deployment);

        return ['data' => $deployment->only(['id', 'status', 'triggered_by', 'commit_hash', 'commit_message', 'started_at', 'finished_at'])
            + ['output' => $deployment->task?->output]];
    }
}
