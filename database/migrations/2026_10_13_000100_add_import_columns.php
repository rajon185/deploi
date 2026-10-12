<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Existing servers connected without provisioning. Deploi only inspects/manages them in place.
            $table->boolean('imported')->default(false)->after('provider_ssh_key_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            // Imported sites keep their own Nginx/Apache config and directory; Deploi never rewrites or deletes them.
            $table->boolean('imported')->default(false)->after('system_user');
            $table->string('directory')->nullable()->after('imported');
            $table->json('meta')->nullable()->after('last_deployed_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['imported', 'directory', 'meta']));
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('imported'));
    }
};
