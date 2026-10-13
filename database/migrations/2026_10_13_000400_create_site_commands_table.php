<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ad-hoc commands run in a site's directory ("Commands" tab). Output lives on the server task.
        Schema::create('site_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_task_id')->nullable()->constrained()->nullOnDelete();
            $table->text('command');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_commands');
    }
};
