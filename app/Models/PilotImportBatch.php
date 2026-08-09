<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PilotImportBatch extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'committed_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'summary' => 'array',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(PilotImportRow::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
