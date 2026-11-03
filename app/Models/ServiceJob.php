<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServiceJob extends Model
{
    protected $fillable = [
        'company_id', 'client_id', 'created_by', 'updated_by',
        'reference_number', 'title', 'description', 'address',
        'start_date', 'due_date', 'budget_chf', 'progress_percentage',
        'stage', 'category', 'completed_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'budget_chf' => 'decimal:2',
            'progress_percentage' => 'decimal:2',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function serviceQuotes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServiceQuote::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'service_job_user', 'service_job_id', 'user_id');
    }

    public function effectiveStage(): string
    {
        if ($this->category === 'archived' || $this->stage === 'completed') {
            return $this->stage;
        }
        return $this->due_date && $this->due_date->isBefore(today())
            ? 'overdue' : $this->stage;
    }
}
