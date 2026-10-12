<x-layout :title="'Environment · '.$site->domain">
    <x-site-header :site="$site" active="environment" />

    @if($error)
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">Could not read the .env file: {{ $error }}</div>
    @endif

    <form method="POST" action="{{ route('sites.environment.update', $site) }}" class="card space-y-4 p-5">
        @csrf @method('PUT')
        <div class="flex items-center justify-between">
            <h2 class="font-semibold">.env</h2>
            <span class="font-mono text-xs text-slate-500">{{ \App\Scripts\SiteScripts::envPath($site) }}</span>
        </div>
        <textarea name="contents" rows="28" spellcheck="false" class="input font-mono text-xs leading-relaxed" @disabled($error)>{{ $contents }}</textarea>
        <div class="flex items-center gap-4">
            <button class="btn btn-primary" @disabled($error)>Save .env</button>
            <p class="hint !mt-0">Read and written live over SSH - nothing is stored in Deploi.</p>
        </div>
    </form>
</x-layout>
