<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AddendumSigningRequest extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_sha256'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function addendum(): BelongsTo { return $this->belongsTo(Addendum::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
