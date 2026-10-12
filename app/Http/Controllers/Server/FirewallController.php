<?php

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\FirewallRule;
use App\Models\Server;
use App\Scripts\ServerScripts;
use App\Tasks\Callbacks\DeleteSubject;
use Illuminate\Http\Request;

class FirewallController extends Controller
{
    public function index(Server $server)
    {
        return view('servers.firewall', [
            'server' => $server,
            'rules' => $server->firewallRules()->orderByDesc('system')->orderBy('port')->get(),
        ]);
    }

    public function store(Request $request, Server $server)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'port' => ['required', 'regex:/^\d{1,5}(:\d{1,5})?$/'],
            'protocol' => ['required', 'in:tcp,udp,any'],
            'from' => ['nullable', 'string', 'max:64', 'regex:/^[0-9a-fA-F.:\/]+$/'],
            'rule_type' => ['required', 'in:allow,deny'],
        ], ['port.regex' => 'Use a single port (3306) or a range (8000:8100).']);

        if (str_contains($data['port'], ':') && $data['protocol'] === 'any') {
            return back()->withErrors(['protocol' => 'Port ranges need a protocol (tcp or udp).'])->withInput();
        }

        $rule = $server->firewallRules()->create($data);
        $server->runTask("Firewall: {$rule->rule_type} {$rule->port}", ServerScripts::addFirewallRule($rule), ['subject' => $rule]);

        return back()->with('success', 'Firewall rule is being added.');
    }

    public function destroy(Server $server, FirewallRule $firewallRule)
    {
        $rule = $firewallRule;

        abort_if($rule->system && $rule->port === '22', 422, 'Removing the SSH rule would lock Deploi out of the server.');

        $rule->update(['status' => 'deleting']);
        $server->runTask("Firewall: remove {$rule->port}", ServerScripts::removeFirewallRule($rule), [
            'subject' => $rule, 'on_finish' => DeleteSubject::class,
        ]);

        return back()->with('success', 'Firewall rule is being removed.');
    }
}
