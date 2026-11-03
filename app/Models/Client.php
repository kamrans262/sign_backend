<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'full_name', 'company_name', 'address',
        'country_iso_code', 'dialing_code', 'phone_number',
        'email', 'notes', 'source',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
