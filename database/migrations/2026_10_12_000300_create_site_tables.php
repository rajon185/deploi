<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->json('aliases')->nullable();
            $table->string('project_type')->default('laravel'); // laravel, php, static, nodejs, wordpress
            $table->string('web_directory')->default('/public');
            $table->string('php_version')->nullable();
            $table->unsignedInteger('app_port')->nullable(); // nodejs reverse proxy port
            $table->string('system_user')->default('deploi');

            // Repository
            $table->foreignId('source_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('repository_provider')->nullable(); // github, gitlab, bitbucket, custom
            $table->string('repository')->nullable(); // owner/repo or full git URL for custom
            $table->string('branch')->nullable();
            $table->boolean('zero_downtime')->default(false);
            $table->unsignedInteger('keep_releases')->default(5);
            $table->text('deploy_script')->nullable();
            $table->string('deploy_webhook_token', 64)->unique();
            $table->boolean('auto_deploy')->default(false);
            $table->string('provider_webhook_id')->nullable();

            $table->string('status')->default('installing'); // installing, active, failed, deleting
            $table->string('repository_status')->nullable(); // installing, installed, failed
            $table->string('ssl_status')->nullable();
            $table->timestamp('last_deployed_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'domain']);
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued'); // queued, running, finished, failed
            $table->string('triggered_by')->default('manual'); // manual, webhook, api
            $table->string('commit_hash')->nullable();
            $table->text('commit_message')->nullable();
            $table->string('commit_author')->nullable();
            $table->string('release')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('letsencrypt'); // letsencrypt | custom
            $table->json('domains');
            $table->text('certificate')->nullable(); // custom cert (encrypted)
            $table->text('private_key')->nullable(); // custom key (encrypted)
            $table->boolean('active')->default(false);
            $table->string('status')->default('installing');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('queue_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('connection')->default('redis');
            $table->string('queue')->default('default');
            $table->unsignedInteger('processes')->default(1);
            $table->unsignedInteger('timeout')->default(60);
            $table->unsignedInteger('sleep')->default(3);
            $table->unsignedInteger('tries')->default(1);
            $table->unsignedInteger('memory')->default(256);
            $table->string('status')->default('installing');
            $table->timestamps();
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('from');
            $table->string('to');
            $table->unsignedSmallInteger('type')->default(301);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('queue_workers');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('sites');
    }
};
