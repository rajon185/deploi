<x-layout :title="'Tasks · '.$server->name">
    <x-server-header :server="$server" active="tasks" />

    <div class="card">
        @include('servers.partials.task-list', ['tasks' => $tasks])
    </div>
    {{ $tasks->links() }}
</x-layout>
