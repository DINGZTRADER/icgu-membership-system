<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StaffOrganisationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'entity_type' => ['nullable', Rule::in(['company', 'ngo', 'sme', 'academic', 'government', 'other'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = Organisation::query()->withCount(['members', 'applications'])->orderBy('legal_name');
        $query->when($filters['entity_type'] ?? null, fn ($q, $type) => $q->where('entity_type', $type));
        $query->when($filters['q'] ?? null, function ($q, string $term): void {
            $q->where(fn ($inner) => $inner
                ->where('legal_name', 'ilike', "%{$term}%")
                ->orWhere('trading_name', 'ilike', "%{$term}%")
                ->orWhere('registration_number', 'ilike', "%{$term}%"));
        });

        return response()->json($query->paginate((int) ($filters['per_page'] ?? 25)));
    }

    public function show(Organisation $organisation): JsonResponse
    {
        return response()->json([
            'data' => $organisation->load([
                'members.status',
                'applications.plan',
                'applications.representatives',
            ]),
        ]);
    }
}
