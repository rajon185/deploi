<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->json('database_engines')->nullable()->after('database_type'); // installed engines; database_type stays the primary one
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->string('engine')->nullable()->after('name'); // mysql | mariadb | postgresql
        });

        Schema::table('database_users', function (Blueprint $table) {
            $table->string('engine')->nullable()->after('username');
        });

        foreach (DB::table('servers')->whereNotNull('database_type')->get(['id', 'database_type']) as $server) {
            DB::table('servers')->where('id', $server->id)->update(['database_engines' => json_encode([$server->database_type])]);
            DB::table('databases')->where('server_id', $server->id)->update(['engine' => $server->database_type]);
            DB::table('database_users')->where('server_id', $server->id)->update(['engine' => $server->database_type]);
        }
    }

    public function down(): void
    {
        Schema::table('database_users', fn (Blueprint $table) => $table->dropColumn('engine'));
        Schema::table('databases', fn (Blueprint $table) => $table->dropColumn('engine'));
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('database_engines'));
    }
};
