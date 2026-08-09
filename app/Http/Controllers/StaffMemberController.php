<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StaffMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['individual', 'corporate'])],
            'status' => ['nullable', 'string', 'max:50'],
            'plan' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = Member::query()
            ->notArchived()
            ->with(['status', 'membershipPlan', 'organisation', 'primaryEmail'])
            ->latest('registration_date');

        $query->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->whereHas('status', fn ($s) => $s->where('code', $status)));
        $query->when($filters['plan'] ?? null, fn ($q, $plan) => $q->whereHas('membershipPlan', fn ($p) => $p->where('code', $plan)));
        $query->when($filters['q'] ?? null, function ($q, string $term): void {
            $q->where(function ($inner) use ($term): void {
                $inner->where('registration_number', 'ilike', "%{$term}%")
                    ->orWhere('first_name', 'ilike', "%{$term}%")
                    ->orWhere('last_name', 'ilike', "%{$term}%")
                    ->orWhere('company_name', 'ilike', "%{$term}%")
                    ->orWhereHas('emails', fn ($email) => $email->where('email', 'ilike', "%{$term}%"));
            });
        });

        return response()->json($query->paginate((int) ($filters['per_page'] ?? 25)));
    }

    public function show(Member $member): JsonResponse
    {
        return response()->json([
            'data' => $member->load([
                'status', 'membershipPlan', 'organisation', 'emails', 'periods',
                'ledgerEntries', 'statusHistory',
                'sourceApplication.invoice.settlements',
                'sourceApplication.payments.receipt',
                'sourceApplication.receipts',
            ]),
        ]);
    }
}
