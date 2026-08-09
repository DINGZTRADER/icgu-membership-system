<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PilotImportRow extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'normalized_payload' => 'array',
        'issues' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PilotImportBatch::class, 'pilot_import_batch_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
