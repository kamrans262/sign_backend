<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceQuoteRevision extends Model
{
    public $timestamps = false;
    protected $fillable = ['service_quote_id','revision','snapshot','actor_user_id','created_at'];
    protected function casts(): array
    {
        return ['snapshot'=>'array','created_at'=>'datetime'];
    }
    public function quote(): BelongsTo { return $this->belongsTo(ServiceQuote::class,'service_quote_id'); }
}
