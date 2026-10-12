@props(['title' => null])
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}Deploi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-slate-800 antialiased">
<div class="flex min-h-full" x-data="{ nav: false }">
    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-30 w-60 -translate-x-full bg-slate-900 text-slate-300 transition lg:translate-x-0"
           :class="nav && 'translate-x-0'">
        <div class="flex h-16 items-center gap-2 px-5">
            <span class="grid size-8 place-items-center rounded-md bg-indigo-500 font-bold text-white">D</span>
            <span class="text-lg font-semibold tracking-tight text-white">Deploi</span>
        </div>

        @php
            $link = fn ($active) => 'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium '.($active ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60 hover:text-white');
        @endphp

        <nav class="space-y-6 px-3 py-4">
            <div class="space-y-1">
                <a href="{{ route('dashboard') }}" class="{{ $link(request()->routeIs('dashboard')) }}">Dashboard</a>
                <a href="{{ route('servers.index') }}" class="{{ $link(request()->routeIs('servers.*') || request()->routeIs('sites.*')) }}">Servers &amp; sites</a>
            </div>

            @php($navServers = auth()->user()->servers()->orderBy('name')->limit(12)->get(['id', 'name', 'status']))
            @if($navServers->isNotEmpty())
                <div>
                    <p class="px-3 pb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Servers</p>
                    <div class="space-y-0.5">
                        @foreach($navServers as $navServer)
                            <a href="{{ route('servers.show', $navServer) }}" class="{{ $link(request()->route('server')?->id === $navServer->id) }} !py-1.5">
                                <span @class(['size-2 rounded-full', 'bg-emerald-400' => $navServer->status === 'active', 'bg-red-400' => in_array($navServer->status, ['failed', 'disconnected']), 'bg-amber-400' => ! in_array($navServer->status, ['active', 'failed', 'disconnected'])])></span>
                                <span class="truncate">{{ $navServer->name }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <p class="px-3 pb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Account</p>
                <div class="space-y-0.5">
                    <a href="{{ route('account.providers') }}" class="{{ $link(request()->routeIs('account.providers')) }}">Providers</a>
                    <a href="{{ route('account.ssh-keys') }}" class="{{ $link(request()->routeIs('account.ssh-keys')) }}">SSH keys</a>
                    <a href="{{ route('account.notifications') }}" class="{{ $link(request()->routeIs('account.notifications')) }}">Notifications</a>
                    <a href="{{ route('account.backup-disks') }}" class="{{ $link(request()->routeIs('account.backup-disks')) }}">Backup storage</a>
                    <a href="{{ route('account.api-tokens') }}" class="{{ $link(request()->routeIs('account.api-tokens')) }}">API tokens</a>
                    <a href="{{ route('account.profile') }}" class="{{ $link(request()->routeIs('account.profile')) }}">Profile</a>
                </div>
            </div>
        </nav>

        <form method="POST" action="{{ route('logout') }}" class="absolute inset-x-0 bottom-0 border-t border-slate-800 p-3">
            @csrf
            <div class="flex items-center justify-between px-2 text-sm">
                <span class="truncate text-slate-400">{{ auth()->user()->name }}</span>
                <button class="text-slate-400 hover:text-white">Log out</button>
            </div>
        </form>
    </aside>

    <div class="fixed inset-0 z-20 bg-black/40 lg:hidden" x-show="nav" x-cloak @click="nav = false"></div>

    <div class="flex min-w-0 flex-1 flex-col lg:pl-60">
        <header class="sticky top-0 z-10 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-8">
            <button class="lg:hidden" @click="nav = true" aria-label="Open navigation">☰</button>
            <div class="min-w-0 flex-1">{{ $header ?? '' }}</div>
            {{ $actions ?? '' }}
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 space-y-6 px-4 py-8 sm:px-8">
            @if(session('success'))
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <ul class="list-inside list-disc space-y-0.5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
