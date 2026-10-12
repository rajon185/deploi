<?php

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSshKey;
use App\Scripts\ServerScripts;
use App\Tasks\Callbacks\DeleteSubject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SshKeyController extends Controller
{
    public function index(Server $server)
    {
        return view('servers.ssh-keys', [
            'server' => $server,
            'keys' => $server->sshKeys()->latest()->get(),
            'accountKeys' => auth()->user()->sshKeys()->get(),
        ]);
    }

    public function store(Request $request, Server $server)
    {
        $data = $request->validate([
            'ssh_key_id' => ['nullable', Rule::exists('ssh_keys', 'id')->where('user_id', auth()->id())],
            'name' => ['required_without:ssh_key_id', 'nullable', 'string', 'max:60'],
            'public_key' => ['required_without:ssh_key_id', 'nullable', 'string', 'regex:/^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-\S+|sk-\S+)\s+[A-Za-z0-9+\/=]+/'],
            'user' => ['required', 'in:root,'.$server->system_user],
        ], ['public_key.regex' => 'That does not look like an OpenSSH public key.']);

        if (! empty($data['ssh_key_id'])) {
            $account = auth()->user()->sshKeys()->findOrFail($data['ssh_key_id']);
            $data['name'] = $account->name;
            $data['public_key'] = $account->public_key;
        }

        $key = $server->sshKeys()->create($data);

        $server->runTask("Add SSH key {$key->name}", ServerScripts::addSshKey($key), ['subject' => $key]);

        return back()->with('success', 'SSH key is being added.');
    }

    public function destroy(Server $server, ServerSshKey $sshKey)
    {
        $key = $sshKey;

        $key->update(['status' => 'deleting']);
        $server->runTask("Remove SSH key {$key->name}", ServerScripts::removeSshKey($key), [
            'subject' => $key, 'on_finish' => DeleteSubject::class,
        ]);

        return back()->with('success', 'SSH key is being removed.');
    }
}
