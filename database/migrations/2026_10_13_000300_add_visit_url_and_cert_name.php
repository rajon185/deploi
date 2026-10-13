<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Where the "Visit" button goes; defaults to the primary domain.
            $table->string('visit_url')->nullable()->after('aliases');
        });

        Schema::table('certificates', function (Blueprint $table) {
            // Certbot / on-disk name, pinned so renaming the site doesn't change the certificate's path.
            $table->string('cert_name')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', fn (Blueprint $table) => $table->dropColumn('cert_name'));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('visit_url'));
    }
};
