<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        // An audit event is never edited or erased through the application.
        // Retention/anonymization requires a separately reviewed policy.
        static::updating(function (): void {
            throw new \LogicException('Audit entries cannot be modified.');
        });

        static::deleting(function (): void {
            throw new \LogicException('Audit entries cannot be deleted.');
        });
    }

    // Append-only log: writes go through AuditLogger, not generic mass assignment.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
