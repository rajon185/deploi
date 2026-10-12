<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cloud provider API credentials (DigitalOcean, Hetzner, Vultr, Linode...).
        Schema::create('server_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('label');
            $table->text('credentials'); // encrypted JSON
            $table->timestamps();
        });

        // Git hosting credentials (GitHub, GitLab, Bitbucket).
        Schema::create('source_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('label');
            $table->string('username')->nullable();
            $table->text('token'); // encrypted
            $table->string('url')->nullable(); // self-hosted GitLab base URL
            $table->timestamps();
        });

        // Account-level SSH keys that can be pushed to servers.
        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('public_key');
            $table->timestamps();
        });

        // Slack / Discord / generic webhook notification channels.
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label');
            $table->text('url'); // encrypted
            $table->json('events')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('ssh_keys');
        Schema::dropIfExists('source_providers');
        Schema::dropIfExists('server_providers');
    }
};
