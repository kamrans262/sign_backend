<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceQuoteItem extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'service_quote_id','position','title','description','quantity','dimensions',
        'unit_price_minor','other_fields','unresolved_fields',
    ];
    protected function casts(): array
    {
        return ['other_fields'=>'array','unresolved_fields'=>'array'];
    }
    public function quote(): BelongsTo { return $this->belongsTo(ServiceQuote::class, 'service_quote_id'); }
}
