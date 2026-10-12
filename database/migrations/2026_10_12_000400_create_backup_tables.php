<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // S3-compatible storage (AWS S3, DigitalOcean Spaces, Backblaze B2, Wasabi, MinIO...).
        Schema::create('backup_disks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('credentials'); // encrypted JSON: key, secret, region, bucket, endpoint
            $table->timestamps();
        });

        Schema::create('database_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_disk_id')->constrained()->cascadeOnDelete();
            $table->string('frequency')->default('0 3 * * *');
            $table->unsignedInteger('keep')->default(7);
            $table->string('path')->default('backups');
            $table->string('status')->default('installing');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backups');
        Schema::dropIfExists('backup_disks');
    }
};
