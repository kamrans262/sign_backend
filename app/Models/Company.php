<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    protected $fillable = [
        'owner_user_id', 'name', 'address', 'country_iso_code',
        'dialing_code', 'phone_number', 'email', 'swiss_company_id',
        'vat_number', 'iban', 'qr_iban', 'document_numbering',
        'logo_path', 'terms_path', 'status',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role', 'status', 'permissions'])->withTimestamps();
    }
}
