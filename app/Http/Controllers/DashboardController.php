<?php

namespace App\Http\Controllers;

use App\Models\Deployment;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        return view('dashboard', [
            'servers' => $user->servers()->withCount('sites')->latest()->get(),
            'sites' => $user->sites()->with('server')->latest('sites.created_at')->limit(10)->get(),
            'deployments' => Deployment::whereIn('site_id', $user->sites()->select('sites.id'))
                ->with('site')->latest()->limit(8)->get(),
            'hasProviders' => $user->serverProviders()->exists(),
        ]);
    }
}
