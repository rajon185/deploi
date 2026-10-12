<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ServerProvider;
use App\Models\SourceProvider;
use App\Services\Providers\ProviderManager;
use App\Services\SourceControl\SourceControlManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class ProviderController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        return view('account.providers', [
            'serverProviders' => $user->serverProviders()->withCount('servers')->get(),
            'sourceProviders' => $user->sourceProviders()->get(),
        ]);
    }

    public function storeServerProvider(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(ProviderManager::PROVIDERS))],
            'label' => ['required', 'string', 'max:60'],
            'token' => ['required', 'string', 'max:500'],
        ]);

        try {
            ProviderManager::make($data['provider'], ['token' => $data['token']])->validate();
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The credentials were rejected: '.$this->apiError($e));
        }

        $request->user()->serverProviders()->create([
            'provider' => $data['provider'],
            'label' => $data['label'],
            'credentials' => ['token' => $data['token']],
        ]);

        return back()->with('success', ProviderManager::name($data['provider']).' connected.');
    }

    public function destroyServerProvider(ServerProvider $provider)
    {
        $this->ensureOwns($provider);
        $provider->delete();

        return back()->with('success', 'Provider removed. Servers created with it stay in Deploi.');
    }

    /** JSON: regions for the create-server form. */
    public function regions(ServerProvider $provider)
    {
        $this->ensureOwns($provider);

        try {
            return response()->json($provider->driver()->regions());
        } catch (Throwable $e) {
            return response()->json(['error' => $this->apiError($e)], 502);
        }
    }

    /** JSON: sizes (optionally filtered by region) for the create-server form. */
    public function sizes(Request $request, ServerProvider $provider)
    {
        $this->ensureOwns($provider);

        try {
            return response()->json($provider->driver()->sizes($request->query('region')));
        } catch (Throwable $e) {
            return response()->json(['error' => $this->apiError($e)], 502);
        }
    }

    /** JSON: machines that already exist in the provider account (for importing). */
    public function servers(ServerProvider $provider)
    {
        $this->ensureOwns($provider);

        try {
            $known = auth()->user()->servers()->where('server_provider_id', $provider->id)->pluck('provider_server_id')->all();

            return response()->json(collect($provider->driver()->listServers())
                ->map(fn ($s) => $s + ['in_deploi' => in_array($s['id'], $known, true)])
                ->values());
        } catch (Throwable $e) {
            return response()->json(['error' => $this->apiError($e)], 502);
        }
    }

    public function storeSourceProvider(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(SourceControlManager::PROVIDERS))],
            'label' => ['required', 'string', 'max:60'],
            'username' => ['nullable', 'required_if:provider,bitbucket', 'string', 'max:120'],
            'token' => ['required', 'string', 'max:500'],
            'url' => ['nullable', 'url', 'max:255'],
        ]);

        $source = new SourceProvider($data);
        $source->user_id = $request->user()->id;

        try {
            $source->username = $source->driver()->validate();
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The token was rejected: '.$this->apiError($e));
        }

        $source->save();

        return back()->with('success', SourceControlManager::PROVIDERS[$data['provider']]['name']." connected as {$source->username}.");
    }

    public function destroySourceProvider(SourceProvider $provider)
    {
        $this->ensureOwns($provider);
        $provider->delete();

        return back()->with('success', 'Source control provider removed.');
    }

    /** JSON: repositories for autocomplete on the install-repository form. */
    public function repositories(SourceProvider $provider)
    {
        $this->ensureOwns($provider);

        try {
            return response()->json($provider->driver()->repositories());
        } catch (Throwable $e) {
            return response()->json(['error' => $this->apiError($e)], 502);
        }
    }

    protected function apiError(Throwable $e): string
    {
        if ($e instanceof RequestException) {
            return $e->response->json('message') ?? $e->response->json('error.message') ?? $e->response->json('error') ?? 'HTTP '.$e->response->status();
        }

        return $e->getMessage();
    }
}
