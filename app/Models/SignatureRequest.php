<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignatureRequest extends Model
{
    protected $fillable = [
        'company_id', 'service_quote_id', 'created_by', 'request_uuid',
        'document_uuid', 'signer_uuid', 'business_signer_uuid',
        'business_user_id', 'business_signer_name', 'mode', 'token_sha256', 'signer_name',
        'signer_email', 'approved_revision', 'approved_pdf_sha256',
        'consent_reference', 'quote_link_version', 'status',
        'expires_at', 'sent_at', 'viewed_at', 'signed_at', 'revoked_at',
    ];

    protected $hidden = ['token_sha256'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime', 'sent_at' => 'datetime',
            'viewed_at' => 'datetime', 'signed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ServiceQuote::class, 'service_quote_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignatureEvent::class)->orderBy('id');
    }

    public function artifact(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SignedDocumentArtifact::class);
    }
}
