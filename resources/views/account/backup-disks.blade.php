<x-layout title="Backup storage">
    <x-slot:header><h1 class="text-lg font-semibold">Backup storage</h1></x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            @if($disks->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">Add S3-compatible storage, then schedule database backups from a server's Databases tab.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach($disks as $disk)
                        <li class="flex items-center justify-between px-5 py-3">
                            <div>
                                <p class="font-medium">{{ $disk->label }}</p>
                                <p class="text-xs text-slate-500">{{ $disk->credentials['bucket'] }} · {{ $disk->credentials['endpoint'] ?: 'AWS S3' }}</p>
                            </div>
                            <x-delete-button :action="route('account.backup-disks.destroy', $disk)" confirm="Remove this storage? Backup schedules using it will be removed from Deploi (server cron entries remain until deleted)." />
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <form method="POST" action="{{ route('account.backup-disks.store') }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">Add S3-compatible storage</h2>
            <div><label class="label">Label</label><input class="input" name="label" value="{{ old('label') }}" placeholder="DO Spaces AMS3" required></div>
            <div><label class="label">Access key</label><input class="input font-mono" name="key" value="{{ old('key') }}" required></div>
            <div><label class="label">Secret key</label><input class="input font-mono" type="password" name="secret" required></div>
            <div><label class="label">Bucket</label><input class="input" name="bucket" value="{{ old('bucket') }}" required></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Region</label><input class="input" name="region" value="{{ old('region') }}" placeholder="ams3"></div>
                <div><label class="label">Endpoint</label><input class="input" name="endpoint" value="{{ old('endpoint') }}" placeholder="https://ams3.digitaloceanspaces.com"></div>
            </div>
            <p class="hint">Leave the endpoint empty for AWS S3. Backups are uploaded by <span class="code">rclone</span> on the server itself.</p>
            <button class="btn btn-primary w-full">Add storage</button>
        </form>
    </div>
</x-layout>
