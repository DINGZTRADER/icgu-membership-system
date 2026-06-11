<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    // Audit logs are APPEND-ONLY. No updates, no deletes, no soft deletes.
    protected $guarded = ['id'];
    protected $casts = [
        'before_payload' => 'array',
        'after_payload'  => 'array',
        'created_at'     => 'immutable_datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────
    public function scopeForEntity(Builder $query, string $entity, int $entityId): Builder
    {
        return $query->where('entity', $entity)->where('entity_id', $entityId);
    }

    public function scopeByAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ── Static Factory ───────────────────────────────────────────────
    public static function record(
        string $action,
        string $entity,
        ?int   $entityId,
        ?array $before = null,
        ?array $after  = null,
        ?int   $userId = null,
        ?string $ip    = null,
        ?string $ua    = null,
    ): self {
        return static::create([
            'user_id'        => $userId ?? auth()->id(),
            'action'         => $action,
            'entity'         => $entity,
            'entity_id'      => $entityId,
            'ip_address'     => $ip ?? request()->ip(),
            'user_agent'     => $ua ?? request()->userAgent(),
            'before_payload' => $before,
            'after_payload'  => $after,
            'session_id'     => session()->getId(),
            'request_id'     => request()->header('X-Request-ID'),
        ]);
    }
}
