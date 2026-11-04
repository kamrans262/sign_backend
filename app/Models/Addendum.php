<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Addendum extends Model
{
    protected $table = 'addenda';
    protected $guarded = [];

    protected $hidden = ['approved_pdf_path', 'signed_pdf_path'];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function quote(): BelongsTo { return $this->belongsTo(ServiceQuote::class, 'service_quote_id'); }
    public function source(): BelongsTo { return $this->belongsTo(SignedDocumentArtifact::class, 'source_signed_artifact_id'); }
    public function signingRequests(): HasMany { return $this->hasMany(AddendumSigningRequest::class)->latest('id'); }
}
