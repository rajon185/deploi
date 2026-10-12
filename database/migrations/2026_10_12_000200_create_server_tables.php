<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('custom');
            $table->string('provider_server_id')->nullable();
            $table->string('name');
            $table->string('type')->default('server'); // server | database | worker | load_balancer
            $table->string('ip_address')->nullable();
            $table->string('private_ip_address')->nullable();
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('ssh_user')->default('root');
            $table->string('system_user')->default('deploi');
            $table->string('region')->nullable();
            $table->string('size')->nullable();
            $table->string('os')->default('ubuntu-24.04');
            $table->string('php_version')->nullable();
            $table->json('installed_php_versions')->nullable();
            $table->string('database_type')->nullable(); // mysql | mariadb | postgresql | null
            $table->string('webserver')->default('nginx');
            $table->boolean('install_redis')->default(true);
            $table->boolean('install_node')->default(true);
            $table->string('status')->default('pending'); // pending, creating, provisioning, active, failed, disconnected, deleting
            $table->string('provision_step')->nullable();
            $table->unsignedTinyInteger('provision_progress')->default(0);
            $table->text('private_key');   // encrypted - Deploi's key for root access
            $table->text('public_key');
            $table->text('sudo_password'); // encrypted
            $table->text('database_password')->nullable(); // encrypted
            $table->text('deploy_public_key')->nullable(); // system user's key (for git deploy keys)
            $table->string('provider_ssh_key_id')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // Every script executed on a server is recorded here (provisioning, deploys, cron writes...).
        Schema::create('server_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('subject');
            $table->string('name');
            $table->string('user')->default('root');
            $table->longText('script');
            $table->longText('output')->nullable();
            $table->string('status')->default('pending'); // pending, running, finished, failed, timeout
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('timeout')->default(600);
            $table->string('on_finish')->nullable(); // callback class
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('installing');
            $table->timestamps();
            $table->unique(['server_id', 'name']);
        });

        Schema::create('database_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('username');
            $table->text('password'); // encrypted
            $table->string('host')->default('%');
            $table->string('status')->default('installing');
            $table->timestamps();
            $table->unique(['server_id', 'username']);
        });

        Schema::create('database_database_user', function (Blueprint $table) {
            $table->foreignId('database_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_user_id')->constrained()->cascadeOnDelete();
            $table->primary(['database_id', 'database_user_id']);
        });

        Schema::create('cronjobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->text('command');
            $table->string('frequency');
            $table->string('user')->default('deploi');
            $table->string('status')->default('installing');
            $table->timestamps();
        });

        Schema::create('daemons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->text('command');
            $table->string('directory')->nullable();
            $table->string('user')->default('deploi');
            $table->unsignedInteger('processes')->default(1);
            $table->unsignedInteger('stop_wait_seconds')->default(10);
            $table->string('status')->default('installing');
            $table->timestamps();
        });

        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('port'); // single port or range 8000:8100
            $table->string('protocol')->default('tcp'); // tcp | udp | any
            $table->string('from')->nullable(); // source IP / CIDR, null = anywhere
            $table->string('rule_type')->default('allow'); // allow | deny
            $table->boolean('system')->default(false); // 22/80/443 created at provisioning
            $table->string('status')->default('installing');
            $table->timestamps();
        });

        Schema::create('server_ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ssh_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('public_key');
            $table->string('user')->default('deploi');
            $table->string('status')->default('installing');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_ssh_keys');
        Schema::dropIfExists('firewall_rules');
        Schema::dropIfExists('daemons');
        Schema::dropIfExists('cronjobs');
        Schema::dropIfExists('database_database_user');
        Schema::dropIfExists('database_users');
        Schema::dropIfExists('databases');
        Schema::dropIfExists('server_tasks');
        Schema::dropIfExists('servers');
    }
};
