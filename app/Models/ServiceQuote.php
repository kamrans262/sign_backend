<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceQuote extends Model
{
    protected $fillable = [
        'company_id','client_id','service_job_id','created_by','updated_by',
        'reference_number','custom_quote_number','client_name','client_email','client_phone','billing_address',
        'quote_date','valid_until','currency','vat_basis_points','deposit_basis_points',
        'terms','payment_details','source_method','status','status_before_archive','revision','link_version','subtotal_minor',
        'vat_minor','total_minor','deposit_minor','approved_at','sent_at','archived_at',
    ];

    protected function casts(): array
    {
        return [
            'quote_date' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d',
            'payment_details' => 'array', 'approved_at' => 'datetime',
            'sent_at' => 'datetime','archived_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function job(): BelongsTo { return $this->belongsTo(ServiceJob::class, 'service_job_id'); }
    public function items(): HasMany { return $this->hasMany(ServiceQuoteItem::class)->orderBy('position'); }
    public function revisions(): HasMany { return $this->hasMany(ServiceQuoteRevision::class)->orderByDesc('revision'); }
}
