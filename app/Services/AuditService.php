<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AuditService
{
    public function record(
        string $action,
        Model|string $entity,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();
        $entityName = $entity instanceof Model ? $entity::class : $entity;
        $entityId ??= $entity instanceof Model ? (int) $entity->getKey() : null;

        return AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity' => $entityName,
            'entity_id' => $entityId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'before_payload' => $before,
            'after_payload' => $after,
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'request_id' => $request->header('X-Request-ID') ?: (string) Str::uuid(),
        ]);
    }
}
