<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MemberPortalAccount extends Model
{
    protected $table = 'member_portal_accounts';
    protected $guarded = ['id'];

    protected $casts = [
        'is_primary' => 'boolean',
        'linked_at' => 'immutable_datetime',
    ];

    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function linkedBy(): BelongsTo { return $this->belongsTo(User::class, 'linked_by'); }
}
