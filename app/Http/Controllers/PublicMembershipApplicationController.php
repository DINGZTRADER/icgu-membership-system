<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MembershipApplication;
use App\Models\MembershipPlan;
use App\Services\MembershipApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

final class PublicMembershipApplicationController extends Controller
{
    public function __construct(private readonly MembershipApplicationService $applications) {}

    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => MembershipPlan::query()
                ->where('is_active', true)
                ->orderBy('first_year_fee')
                ->get(['code', 'name', 'audience', 'first_year_fee', 'renewal_fee', 'currency', 'requirements']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $base = $request->validate([
            'plan_code' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'applicant_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $plan = MembershipPlan::query()->where('code', $base['plan_code'])->where('is_active', true)->first();
        if ($plan === null) {
            throw ValidationException::withMessages(['plan_code' => 'Selected membership plan is unavailable.']);
        }

        $details = match ($plan->audience) {
            'corporate' => $request->validate([
                'organisation' => ['required', 'array'],
                'organisation.legal_name' => ['required', 'string', 'max:200'],
                'organisation.trading_name' => ['nullable', 'string', 'max:200'],
                'organisation.registration_number' => ['required', 'string', 'max:120', 'unique:organisations,registration_number'],
                'organisation.entity_type' => ['required', Rule::in(['company', 'ngo', 'sme', 'academic', 'government', 'other'])],
                'organisation.tin' => ['nullable', 'string', 'max:60'],
                'organisation.email' => ['nullable', 'email:rfc', 'max:254'],
                'organisation.phone' => ['nullable', 'string', 'max:40'],
                'organisation.website' => ['nullable', 'url', 'max:255'],
                'organisation.address_line' => ['nullable', 'string', 'max:255'],
                'organisation.city' => ['nullable', 'string', 'max:100'],
                'organisation.country' => ['nullable', 'string', 'max:100'],
                'organisation.industry' => ['nullable', 'string', 'max:150'],
                'organisation.profile_summary' => ['nullable', 'string', 'max:5000'],
                'representatives' => ['required', 'array', 'min:1', 'max:10'],
                'representatives.*.title' => ['nullable', 'string', 'max:20'],
                'representatives.*.first_name' => ['required', 'string', 'max:100'],
                'representatives.*.last_name' => ['required', 'string', 'max:100'],
                'representatives.*.email' => ['required', 'email:rfc', 'max:254'],
                'representatives.*.phone' => ['nullable', 'string', 'max:40'],
                'representatives.*.position' => ['nullable', 'string', 'max:150'],
                'representatives.*.is_primary' => ['nullable', 'boolean'],
            ]),
            'student' => $request->validate([
                'title' => ['nullable', 'string', 'max:20'],
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'institution_name' => ['required', 'string', 'max:200'],
            ]),
            default => $request->validate([
                'title' => ['nullable', 'string', 'max:20'],
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'job_title' => ['nullable', 'string', 'max:150'],
            ]),
        };

        $payload = array_merge($base, $details);
        if ($plan->audience === 'corporate') {
            $payload['organisation']['country'] ??= 'Uganda';
            $payload['organisation']['entity_type'] = match ($plan->code) {
                'ngo-corporate' => 'ngo',
                'sme-corporate' => 'sme',
                default => $payload['organisation']['entity_type'],
            };
            $payload['representatives'][0]['is_primary'] = true;
            foreach (array_keys($payload['representatives']) as $index) {
                if ($index !== 0) {
                    $payload['representatives'][$index]['is_primary'] = false;
                }
            }
        }

        $created = $this->applications->create($payload);

        return response()->json([
            'data' => $this->present($created['application']),
            'application_token' => $created['token'],
            'token_notice' => 'Store this token securely. It is required to resume or submit this application.',
        ], 201);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $application = $this->find($reference);
        $this->applications->authorizeToken($application, $this->token($request));

        return response()->json(['data' => $this->present($application)]);
    }

    public function uploadDocument(Request $request, string $reference): JsonResponse
    {
        $application = $this->find($reference);
        $this->applications->authorizeToken($application, $this->token($request));

        $validated = $request->validate([
            'document_type' => ['required', Rule::in(['cv', 'passport_photo', 'company_profile', 'registration_certificate', 'student_evidence', 'other'])],
            'representative_id' => ['nullable', 'integer'],
            'file' => ['required', File::types(['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])->max('10mb')],
        ]);

        $document = $this->applications->storeDocument(
            $application,
            $validated['file'],
            $validated['document_type'],
            isset($validated['representative_id']) ? (int) $validated['representative_id'] : null,
        );

        return response()->json([
            'data' => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
                'representative_id' => $document->application_representative_id,
            ],
            'missing_requirements' => $this->applications->missingRequirements($application->refresh()),
        ], 201);
    }

    public function submit(Request $request, string $reference): JsonResponse
    {
        $application = $this->find($reference);
        $this->applications->authorizeToken($application, $this->token($request));
        $validated = $request->validate([
            'integrity_declaration' => ['accepted'],
            'terms_accepted' => ['accepted'],
        ]);

        $application = $this->applications->submit(
            $application,
            (bool) $validated['integrity_declaration'],
            (bool) $validated['terms_accepted'],
        );

        return response()->json(['data' => $this->present($application)]);
    }

    private function find(string $reference): MembershipApplication
    {
        return MembershipApplication::query()
            ->where('reference', $reference)
            ->with(['plan', 'organisation', 'representatives', 'documents'])
            ->firstOrFail();
    }

    private function token(Request $request): string
    {
        return (string) $request->header('X-Application-Token', '');
    }

    private function present(MembershipApplication $application): array
    {
        $application->loadMissing(['plan', 'organisation', 'representatives', 'documents']);

        return [
            'reference' => $application->reference,
            'status' => $application->status,
            'plan' => [
                'code' => $application->plan->code,
                'name' => $application->plan->name,
                'first_year_fee' => $application->plan->first_year_fee,
                'currency' => $application->plan->currency,
            ],
            'applicant' => [
                'title' => $application->title,
                'first_name' => $application->first_name,
                'last_name' => $application->last_name,
                'email' => $application->email,
                'phone' => $application->phone,
                'job_title' => $application->job_title,
                'institution_name' => $application->institution_name,
            ],
            'organisation' => $application->organisation,
            'representatives' => $application->representatives,
            'documents' => $application->documents->map(fn ($document) => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
                'representative_id' => $document->application_representative_id,
                'verified_at' => $document->verified_at,
            ])->values(),
            'missing_requirements' => $this->applications->missingRequirements($application),
            'submitted_at' => $application->submitted_at,
            'decision_notes' => $application->status === 'rejected' ? $application->decision_notes : null,
        ];
    }
}
