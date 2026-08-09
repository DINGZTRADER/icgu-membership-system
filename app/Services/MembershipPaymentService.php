<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinancialLedger;
use App\Models\LookupStatus;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MembershipPaymentService
{
    public function __construct(
        private readonly FinancialDocumentNumberService $numbers,
        private readonly RegistrationNumberService $registrations,
        private readonly MembershipApplicationService $applications,
        private readonly AuditService $audit,
    ) {}

    public function createInvoice(MembershipApplication $application, User $actor, ?\DateTimeInterface $dueDate = null): FinancialLedger
    {
        $application->loadMissing('plan');
        $this->assertApprovedPendingPayment($application);

        $existing = FinancialLedger::query()
            ->where('membership_application_id', $application->id)
            ->where('type', 'invoice')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($application, $actor, $dueDate): FinancialLedger {
            $invoice = FinancialLedger::query()->create([
                'membership_application_id' => $application->id,
                'member_id' => null,
                'status_id' => $this->paymentStatusId('PAY_PENDING'),
                'type' => 'invoice',
                'invoice_number' => $this->numbers->invoice(),
                'fee_type' => $application->plan->audience === 'corporate' ? 'annual_corporate' : 'annual_individual',
                'amount' => $application->plan->first_year_fee,
                'amount_settled' => 0,
                'currency' => $application->plan->currency,
                'due_date' => $dueDate ?? now()->addDays(14),
                'notes' => 'First-year membership subscription invoice.',
                'created_by' => $actor->id,
            ]);

            $this->audit->record('membership_invoice_created', $invoice, after: [
                'application_reference' => $application->reference,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
            ]);

            return $invoice;
        });
    }

    /** @return array{payment: FinancialLedger, receipt: Receipt, balance_due: string} */
    public function recordPayment(
        MembershipApplication $application,
        FinancialLedger $invoice,
        string $amount,
        string $method,
        string $transactionReference,
        User $actor,
        ?string $provider = null,
        ?\DateTimeInterface $receivedAt = null,
    ): array {
        $this->assertApprovedPendingPayment($application);

        if ($invoice->type !== 'invoice' || (int) $invoice->membership_application_id !== (int) $application->id) {
            throw ValidationException::withMessages(['invoice' => 'Invoice does not belong to this membership application.']);
        }

        if (! in_array($method, ['bank_transfer', 'mobile_money', 'cash', 'card', 'cheque', 'other'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Unsupported payment method.']);
        }

        $amountValue = round((float) $amount, 4);
        $balanceBefore = (float) $this->balanceDue($invoice);

        if ($amountValue <= 0) {
            throw ValidationException::withMessages(['amount' => 'Payment amount must be greater than zero.']);
        }
        if ($amountValue - $balanceBefore > 0.0001) {
            throw ValidationException::withMessages(['amount' => 'Payment exceeds the outstanding invoice balance.']);
        }

        return DB::transaction(function () use ($application, $invoice, $amountValue, $method, $transactionReference, $actor, $provider, $receivedAt): array {
            $payment = FinancialLedger::query()->create([
                'membership_application_id' => $application->id,
                'member_id' => null,
                'status_id' => $this->paymentStatusId('PAY_PAID'),
                'type' => 'payment',
                'fee_type' => $invoice->fee_type,
                'amount' => $amountValue,
                'amount_settled' => 0,
                'tx_reference' => $transactionReference,
                'payment_method' => $method,
                'payment_provider' => $provider,
                'currency' => $invoice->currency,
                'parent_invoice_id' => $invoice->id,
                'received_at' => $receivedAt ?? now(),
                'settled_at' => $receivedAt ?? now(),
                'notes' => 'Membership subscription payment.',
                'created_by' => $actor->id,
            ]);

            $receipt = new Receipt();
            $receipt->forceFill([
                'receipt_number' => $this->numbers->receipt(),
                'payment_ledger_id' => $payment->id,
                'membership_application_id' => $application->id,
                'member_id' => null,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'payment_reference' => $transactionReference,
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'meta' => ['payment_method' => $method, 'payment_provider' => $provider],
            ])->save();

            $balance = $this->balanceDue($invoice);
            $this->audit->record('membership_payment_recorded', $payment, after: [
                'application_reference' => $application->reference,
                'invoice_number' => $invoice->invoice_number,
                'receipt_number' => $receipt->receipt_number,
                'amount' => $payment->amount,
                'balance_due' => $balance,
                'tx_reference' => $transactionReference,
            ]);

            return ['payment' => $payment, 'receipt' => $receipt, 'balance_due' => $balance];
        });
    }

    public function balanceDue(FinancialLedger $invoice): string
    {
        $entries = FinancialLedger::query()
            ->where('parent_invoice_id', $invoice->id)
            ->whereIn('type', ['payment', 'waiver', 'refund'])
            ->get(['type', 'amount']);

        $settled = 0.0;
        foreach ($entries as $entry) {
            $settled += $entry->type === 'refund' ? -(float) $entry->amount : (float) $entry->amount;
        }

        return number_format(max(0, (float) $invoice->amount - $settled), 4, '.', '');
    }

    public function admit(MembershipApplication $application, User $actor): Member
    {
        $application->loadMissing(['plan', 'organisation', 'representatives', 'documents']);
        $this->assertApprovedPendingPayment($application);

        $missing = $this->applications->missingRequirements($application);
        if ($missing !== []) {
            throw ValidationException::withMessages(['documents' => 'Application requirements are incomplete: '.implode(', ', $missing)]);
        }

        $invoice = FinancialLedger::query()
            ->where('membership_application_id', $application->id)
            ->where('type', 'invoice')
            ->first();

        if ($invoice === null || (float) $this->balanceDue($invoice) > 0.0001) {
            throw ValidationException::withMessages(['payment' => 'The membership subscription invoice must be fully paid before admission.']);
        }

        if ($application->resulting_member_id !== null) {
            return $application->resultingMember()->firstOrFail();
        }

        return DB::transaction(function () use ($application, $actor): Member {
            $activeStatusId = LookupStatus::query()->where('type', 'membership')->where('code', 'ACTIVE')->value('id');
            if ($activeStatusId === null) {
                throw new \RuntimeException('ACTIVE membership status is not configured.');
            }

            $member = new Member();
            $member->forceFill([
                'registration_number' => $this->registrations->generate(),
                'type' => $application->plan->audience === 'corporate' ? 'corporate' : 'individual',
                'title' => $application->title,
                'first_name' => $application->plan->audience === 'corporate' ? null : $application->first_name,
                'last_name' => $application->plan->audience === 'corporate' ? null : $application->last_name,
                'company_name' => $application->plan->audience === 'corporate' ? $application->organisation?->legal_name : null,
                'phone' => $application->phone,
                'organization' => $application->plan->audience === 'corporate' ? null : $application->institution_name,
                'job_title' => $application->job_title,
                'registration_date' => now()->toDateString(),
                'status_id' => $activeStatusId,
                'membership_plan_id' => $application->membership_plan_id,
                'source_application_id' => $application->id,
                'organisation_id' => $application->organisation_id,
            ])->save();

            $member->emails()->create([
                'email' => mb_strtolower($application->email),
                'email_type' => 'work',
                'is_primary' => true,
                'is_active' => true,
            ]);

            $start = now()->startOfDay();
            $member->periods()->create([
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addYear()->subDay()->toDateString(),
                'target_year' => (int) $start->format('Y'),
                'is_backdated' => false,
                'is_future' => false,
                'notes' => 'Initial one-year membership period created after full subscription payment.',
                'created_by' => $actor->id,
            ]);

            $member->statusHistory()->create([
                'from_status_id' => null,
                'to_status_id' => $activeStatusId,
                'reason_code' => 'INITIAL_SUBSCRIPTION_PAID',
                'reason_notes' => 'Membership admitted after committee approval, complete requirements and full payment.',
                'effective_at' => now(),
                'actor_id' => $actor->id,
            ]);

            $application->forceFill([
                'status' => 'admitted',
                'resulting_member_id' => $member->id,
                'version' => $application->version + 1,
            ])->save();

            $this->audit->record('membership_admitted', $member, after: [
                'application_reference' => $application->reference,
                'registration_number' => $member->registration_number,
                'membership_plan_id' => $member->membership_plan_id,
                'status' => 'ACTIVE',
            ]);

            return $member->load(['membershipPlan', 'organisation', 'primaryEmail', 'currentPeriod']);
        });
    }

    private function assertApprovedPendingPayment(MembershipApplication $application): void
    {
        if ($application->status !== 'approved_pending_payment') {
            throw ValidationException::withMessages(['status' => 'Application must be approved and awaiting payment.']);
        }
    }

    private function paymentStatusId(string $code): int
    {
        $id = LookupStatus::query()->where('type', 'payment')->where('code', $code)->value('id');
        if ($id === null) {
            throw new \RuntimeException("Payment status {$code} is not configured.");
        }

        return (int) $id;
    }
}
