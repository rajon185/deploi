<?php

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Database;
use App\Models\DatabaseBackup;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Scripts\ServerScripts;
use App\Tasks\Callbacks\BackupRan;
use App\Tasks\Callbacks\DeleteSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DatabaseController extends Controller
{
    public function index(Server $server)
    {
        return view('servers.databases', [
            'server' => $server,
            'databases' => $server->databases()->with(['users', 'backups.disk'])->orderBy('name')->get(),
            'users' => $server->databaseUsers()->with('databases')->orderBy('username')->get(),
            'disks' => auth()->user()->backupDisks()->get(),
        ]);
    }

    public function store(Request $request, Server $server)
    {
        abort_unless($server->hasDatabase(), 422, 'This server has no database engine.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('databases')->where('server_id', $server->id)],
            'username' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('database_users')->where('server_id', $server->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9!@#%^&*()_+\-=.,:;?]+$/'],
        ]);

        $database = $server->databases()->create(['name' => $data['name']]);
        $server->runTask("Create database {$database->name}", ServerScripts::createDatabase($database), ['subject' => $database]);

        if (! empty($data['username'])) {
            $user = $server->databaseUsers()->create([
                'username' => $data['username'],
                'password' => $data['password'] ?: Str::password(24, symbols: false),
            ]);
            $user->databases()->attach($database);
            $server->runTask("Create database user {$user->username}", ServerScripts::syncDatabaseUser($user->load('databases')), ['subject' => $user]);
        }

        return back()->with('success', "Database {$database->name} is being created.");
    }

    public function destroy(Server $server, Database $database)
    {
        $database->update(['status' => 'deleting']);
        $server->runTask("Drop database {$database->name}", ServerScripts::dropDatabase($database), [
            'subject' => $database, 'on_finish' => DeleteSubject::class,
        ]);

        return back()->with('success', "Database {$database->name} is being dropped.");
    }

    public function storeUser(Request $request, Server $server)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('database_users')->where('server_id', $server->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9!@#%^&*()_+\-=.,:;?]+$/'],
            'databases' => ['array'],
            'databases.*' => [Rule::exists('databases', 'id')->where('server_id', $server->id)],
        ]);

        $user = $server->databaseUsers()->create([
            'username' => $data['username'],
            'password' => $data['password'] ?: Str::password(24, symbols: false),
        ]);
        $user->databases()->sync($data['databases'] ?? []);

        $server->runTask("Create database user {$user->username}", ServerScripts::syncDatabaseUser($user->load('databases')), ['subject' => $user]);

        return back()->with('success', "User {$user->username} is being created.");
    }

    public function updateUser(Request $request, Server $server, DatabaseUser $databaseUser)
    {
        $user = $databaseUser;
        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9!@#%^&*()_+\-=.,:;?]+$/'],
            'databases' => ['array'],
            'databases.*' => [Rule::exists('databases', 'id')->where('server_id', $server->id)],
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->status = 'installing';
        $user->save();
        $user->databases()->sync($data['databases'] ?? []);

        $server->runTask("Update database user {$user->username}", ServerScripts::syncDatabaseUser($user->load('databases')), ['subject' => $user]);

        return back()->with('success', "User {$user->username} is being updated.");
    }

    public function destroyUser(Server $server, DatabaseUser $databaseUser)
    {
        $user = $databaseUser;
        $user->update(['status' => 'deleting']);
        $server->runTask("Drop database user {$user->username}", ServerScripts::dropDatabaseUser($user), [
            'subject' => $user, 'on_finish' => DeleteSubject::class,
        ]);

        return back()->with('success', "User {$user->username} is being removed.");
    }

    public function revealPassword(Server $server, DatabaseUser $databaseUser)
    {
        return response()->json(['password' => $databaseUser->password]);
    }

    // ------------------------------------------------------------ Backups

    public function storeBackup(Request $request, Server $server, Database $database)
    {
        $data = $request->validate([
            'backup_disk_id' => ['required', Rule::exists('backup_disks', 'id')->where('user_id', auth()->id())],
            'frequency' => ['required', 'string', 'regex:/^[0-9*\/,\- ]+$/'],
            'keep' => ['required', 'integer', 'between:1,365'],
            'path' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\/.-]*$/'],
        ]);

        $backup = $database->backups()->create([
            ...$data,
            'path' => $data['path'] ?: 'deploi-backups',
        ]);

        $server->runTask("Schedule backups for {$database->name}", ServerScripts::installBackup($backup->load('database.server', 'disk')), ['subject' => $backup]);

        return back()->with('success', 'Backup schedule is being installed.');
    }

    public function runBackup(Server $server, Database $database, DatabaseBackup $backup)
    {
        $server->runTask("Back up {$database->name}", ServerScripts::runBackup($backup), [
            'subject' => $backup, 'on_finish' => BackupRan::class, 'timeout' => 3600,
        ]);

        return back()->with('success', 'Backup started - check the Tasks tab for progress.');
    }

    public function destroyBackup(Server $server, Database $database, DatabaseBackup $backup)
    {
        $server->runTask("Remove backup schedule for {$database->name}", ServerScripts::removeBackup($backup), [
            'subject' => $backup, 'on_finish' => DeleteSubject::class,
        ]);

        return back()->with('success', 'Backup schedule is being removed.');
    }
}
