<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MembershipApplication;
use App\Services\MembershipApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StaffMembershipApplicationController extends Controller
{
    public function __construct(private readonly MembershipApplicationService $applications) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'under_review', 'approved_pending_payment', 'rejected', 'withdrawn', 'admitted'])],
            'plan' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = MembershipApplication::query()->with(['plan', 'organisation', 'representatives'])->latest('submitted_at');

        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['plan'] ?? null, fn ($q, $plan) => $q->whereHas('plan', fn ($p) => $p->where('code', $plan)));
        $query->when($filters['q'] ?? null, function ($q, string $term): void {
            $q->where(function ($inner) use ($term): void {
                $inner->where('reference', 'ilike', "%{$term}%")
                    ->orWhere('email', 'ilike', "%{$term}%")
                    ->orWhereHas('organisation', fn ($org) => $org->where('legal_name', 'ilike', "%{$term}%"));
            });
        });

        return response()->json($query->paginate((int) ($filters['per_page'] ?? 25)));
    }

    public function show(string $reference): JsonResponse
    {
        return response()->json([
            'data' => $this->find($reference)->load(['plan', 'organisation', 'representatives', 'documents']),
        ]);
    }

    public function startReview(Request $request, string $reference): JsonResponse
    {
        $application = $this->applications->startReview($this->find($reference), $request->user());
        return response()->json(['data' => $application]);
    }

    public function approve(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $application = $this->applications->approvePendingPayment($this->find($reference), $request->user(), $validated['notes'] ?? null);
        return response()->json(['data' => $application]);
    }

    public function reject(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:5000']]);
        $application = $this->applications->reject($this->find($reference), $request->user(), $validated['reason']);
        return response()->json(['data' => $application]);
    }

    private function find(string $reference): MembershipApplication
    {
        return MembershipApplication::query()->where('reference', $reference)->firstOrFail();
    }
}
