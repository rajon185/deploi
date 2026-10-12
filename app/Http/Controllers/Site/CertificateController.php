<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Site;
use App\Scripts\ServerScripts;
use App\Tasks\Callbacks\CertificateInstalled;
use App\Tasks\Callbacks\DeleteSubject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CertificateController extends Controller
{
    public function index(Site $site)
    {
        return view('sites.ssl', [
            'site' => $site,
            'server' => $site->server,
            'certificates' => $site->certificates()->latest()->get(),
        ]);
    }

    public function letsEncrypt(Request $request, Site $site)
    {
        $data = $request->validate([
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => [Rule::in($site->allDomains())],
        ]);

        $cert = $site->certificates()->create(['type' => 'letsencrypt', 'domains' => array_values($data['domains'])]);
        $site->update(['ssl_status' => 'installing']);

        $script = $site->managesWebserverConfig()
            ? ServerScripts::issueLetsEncrypt($cert, $request->user()->email)
            : ServerScripts::issueLetsEncryptInPlace($cert, $request->user()->email);

        $site->server->runTask("Let's Encrypt for {$site->domain}", $script, [
            'subject' => $cert, 'on_finish' => CertificateInstalled::class, 'timeout' => 300,
        ]);

        return back()->with('success', "Requesting a Let's Encrypt certificate. DNS for every domain must already point to {$site->server->ip_address}.");
    }

    public function custom(Request $request, Site $site)
    {
        abort_unless($site->managesWebserverConfig(), 422, 'Install custom certificates on imported sites in their own web server config.');

        $data = $request->validate([
            'certificate' => ['required', 'string', 'regex:/-----BEGIN CERTIFICATE-----/'],
            'private_key' => ['required', 'string', 'regex:/-----BEGIN [A-Z ]*PRIVATE KEY-----/'],
        ]);

        $cert = $site->certificates()->create([
            'type' => 'custom',
            'domains' => $site->allDomains(),
            'certificate' => trim($data['certificate'])."\n",
            'private_key' => trim($data['private_key'])."\n",
        ]);

        $site->server->runTask("Install custom certificate for {$site->domain}", ServerScripts::installCustomCertificate($cert), [
            'subject' => $cert, 'on_finish' => CertificateInstalled::class,
        ]);

        return back()->with('success', 'Certificate is being installed.');
    }

    public function destroy(Site $site, Certificate $certificate)
    {
        // On imported sites the vhost references the certificate; deleting it would break the site.
        if (! $site->managesWebserverConfig()) {
            $certificate->delete();

            return back()->with('success', 'Certificate removed from Deploi. It is still installed and renewing on the server.');
        }

        $wasActive = $certificate->active;
        $certificate->update(['status' => 'deleting']);

        if ($wasActive) {
            $site->update(['ssl_status' => null]);
            // So the regenerated vhost doesn't pick this certificate again.
            $certificate->update(['active' => false]);
        }

        $site->server->runTask("Remove certificate from {$site->domain}", ServerScripts::removeCertificate($certificate, $wasActive), [
            'subject' => $certificate, 'on_finish' => DeleteSubject::class,
        ]);

        return back()->with('success', 'Certificate is being removed.');
    }
}
