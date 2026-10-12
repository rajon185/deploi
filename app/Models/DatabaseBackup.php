<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseBackup extends Model
{
    protected $fillable = ['backup_disk_id', 'frequency', 'keep', 'path', 'status', 'last_run_at'];

    protected function casts(): array
    {
        return ['last_run_at' => 'datetime'];
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function disk(): BelongsTo
    {
        return $this->belongsTo(BackupDisk::class, 'backup_disk_id');
    }

    public function scriptPath(): string
    {
        return "/root/.deploi/backups/backup-{$this->id}.sh";
    }
}
