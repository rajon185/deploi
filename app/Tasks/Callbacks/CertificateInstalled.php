<?php

namespace App\Tasks\Callbacks;

use App\Models\Certificate;
use App\Models\ServerTask;
use Illuminate\Support\Carbon;

class CertificateInstalled implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        /** @var Certificate|null $cert */
        $cert = $task->subject;

        if (! $cert) {
            return;
        }

        if (! $task->successful()) {
            $cert->update(['status' => 'failed']);
            // Don't leave the site showing "installing" forever: fall back to whatever was there before.
            $cert->site->update(['ssl_status' => $cert->site->activeCertificate() ? 'active' : null]);

            return;
        }

        $expires = null;
        if (preg_match('/##DEPLOI_CERT_EXPIRY:(.+?)##/', $task->output ?? '', $m)) {
            try {
                $expires = Carbon::parse(trim($m[1]));
            } catch (\Throwable) {
            }
        }

        $cert->site->certificates()->whereKeyNot($cert->id)->update(['active' => false]);
        $cert->update(['status' => 'active', 'active' => true, 'expires_at' => $expires]);
        $cert->site->update(['ssl_status' => 'active']);
    }
}
