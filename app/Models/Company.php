<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'owner_user_id', 'name', 'address', 'country_iso_code',
        'dialing_code', 'phone_number', 'email', 'swiss_company_id',
        'vat_number', 'iban', 'qr_iban', 'document_numbering',
        'logo_path', 'terms_path', 'logo_original_name', 'terms_original_name', 'status',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function serviceQuotes(): HasMany
    {
        return $this->hasMany(ServiceQuote::class);
    }

    public function serviceJobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function customRoles(): HasMany
    {
        return $this->hasMany(CompanyRole::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
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
