<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FinancialLedger;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\MembershipRenewal;
use App\Models\Organisation;
use App\Models\Receipt;
use App\Services\MemberPortalService;
use App\Services\MembershipApplicationService;
use App\Services\MembershipPaymentService;
use App\Services\MembershipRenewalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StaffPortalPageController extends Controller
{
    public function __construct(
        private readonly MembershipApplicationService $applications,
        private readonly MembershipPaymentService $payments,
        private readonly MembershipRenewalService $renewals,
        private readonly MemberPortalService $portal,
    ) {}

    public function loginForm(): View
    {
        return view('staff.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([
            'email' => mb_strtolower($credentials['email']),
            'password' => $credentials['password'],
            'is_active' => true,
        ], true)) {
            throw ValidationException::withMessages(['email' => 'The supplied staff credentials are invalid.']);
        }

        $user = $request->user();
        if (! $user->roles()->where('slug', '<>', 'member')->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw ValidationException::withMessages(['email' => 'This account does not have Secretariat access.']);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('staff.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('staff.login');
    }

    public function dashboard(Request $request): View
    {
        $today = today();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $outstandingInvoices = FinancialLedger::query()->invoices()->outstanding()->with('settlements')->get();
        $grossCollections = FinancialLedger::query()->payments()
            ->where(function ($query) use ($monthStart, $monthEnd): void {
                $query->whereBetween('received_at', [$monthStart, $monthEnd])
                    ->orWhere(function ($fallback) use ($monthStart, $monthEnd): void {
                        $fallback->whereNull('received_at')->whereBetween('created_at', [$monthStart, $monthEnd]);
                    });
            })->sum('amount');

        $stats = [
            'members_total' => Member::query()->notArchived()->count(),
            'members_active' => Member::query()->notArchived()->active()->count(),
            'members_expired' => Member::query()->notArchived()->expired()->count(),
            'corporates' => Member::query()->notArchived()->corporates()->count(),
            'applications_open' => MembershipApplication::query()->whereIn('status', ['submitted', 'under_review', 'approved_pending_payment'])->count(),
            'applications_decision' => MembershipApplication::query()->whereIn('status', ['submitted', 'under_review'])->count(),
            'renewals_open' => MembershipRenewal::query()->open()->count(),
            'overdue_invoices' => FinancialLedger::query()->invoices()->overdue()->count(),
            'outstanding_balance' => $outstandingInvoices->sum(fn (FinancialLedger $invoice): float => (float) $invoice->balance_due),
            'gross_collections_month' => (float) $grossCollections,
        ];

        $attentionApplications = MembershipApplication::query()
            ->with(['plan', 'organisation', 'invoice.settlements'])
            ->whereIn('status', ['submitted', 'under_review', 'approved_pending_payment'])
            ->orderByRaw("CASE status WHEN 'submitted' THEN 1 WHEN 'under_review' THEN 2 ELSE 3 END")
            ->orderBy('submitted_at')
            ->limit(8)
            ->get();

        $expiringMembers = Member::query()
            ->notArchived()->active()
            ->with(['membershipPlan', 'status', 'currentPeriod'])
            ->whereHas('periods', fn ($query) => $query->whereBetween('end_date', [$today, $today->copy()->addDays(60)]))
            ->limit(8)
            ->get();

        $recentAudit = AuditLog::query()->with('user')->latest()->limit(10)->get();
        $roles = $request->user()->roles()->pluck('name');

        return view('staff.dashboard', compact('stats', 'attentionApplications', 'expiringMembers', 'recentAudit', 'roles'));
    }

    public function applications(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'under_review', 'approved_pending_payment', 'rejected', 'withdrawn', 'admitted'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = MembershipApplication::query()->with(['plan', 'organisation', 'invoice.settlements'])->latest('submitted_at');
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['q'] ?? null, function ($q, string $term): void {
            $q->where(function ($inner) use ($term): void {
                $inner->where('reference', 'ilike', "%{$term}%")
                    ->orWhere('email', 'ilike', "%{$term}%")
                    ->orWhere('first_name', 'ilike', "%{$term}%")
                    ->orWhere('last_name', 'ilike', "%{$term}%")
                    ->orWhereHas('organisation', fn ($org) => $org->where('legal_name', 'ilike', "%{$term}%"));
            });
        });

        $applications = $query->paginate(25)->withQueryString();

        return view('staff.applications.index', compact('applications', 'filters'));
    }

    public function application(string $reference): View
    {
        $application = $this->applicationByReference($reference)->load([
            'plan', 'organisation', 'representatives', 'documents', 'decisionMaker',
            'invoice.settlements', 'payments.receipt', 'receipts', 'resultingMember.status',
        ]);

        return view('staff.applications.show', compact('application'));
    }

    public function startReview(Request $request, string $reference): RedirectResponse
    {
        $this->applications->startReview($this->applicationByReference($reference), $request->user());
        return back()->with('success', 'Application moved into formal review.');
    }

    public function approve(Request $request, string $reference): RedirectResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $application = $this->applications->approvePendingPayment($this->applicationByReference($reference), $request->user(), $validated['notes'] ?? null);
        $this->payments->createInvoice($application, $request->user());

        return back()->with('success', 'Application approved pending payment and the first-year invoice is ready.');
    }

    public function reject(Request $request, string $reference): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:5000']]);
        $this->applications->reject($this->applicationByReference($reference), $request->user(), $validated['reason']);

        return back()->with('success', 'Application rejected and the decision was recorded in the audit trail.');
    }

    public function recordApplicationPayment(Request $request, string $reference): RedirectResponse
    {
        $validated = $this->validatePayment($request, true);
        $application = $this->applicationByReference($reference);
        $invoice = FinancialLedger::query()->findOrFail((int) $validated['invoice_id']);

        $this->payments->recordPayment(
            $application,
            $invoice,
            (string) $validated['amount'],
            $validated['payment_method'],
            $validated['transaction_reference'],
            $request->user(),
            $validated['payment_provider'] ?? null,
            isset($validated['received_at']) ? new \DateTimeImmutable($validated['received_at']) : null,
        );

        return back()->with('success', 'Payment recorded and receipt issued.');
    }

    public function admit(Request $request, string $reference): RedirectResponse
    {
        $member = $this->payments->admit($this->applicationByReference($reference), $request->user());
        return redirect()->route('staff.members.show', $member)->with('success', 'Applicant admitted as an active ICGU member.');
    }

    public function members(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['individual', 'corporate'])],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $query = Member::query()->notArchived()->with(['status', 'membershipPlan', 'organisation', 'primaryEmail', 'latestPeriod'])->latest('registration_date');
        $query->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->whereHas('status', fn ($s) => $s->where('code', $status)));
        $query->when($filters['q'] ?? null, function ($q, string $term): void {
            $q->where(function ($inner) use ($term): void {
                $inner->where('registration_number', 'ilike', "%{$term}%")
                    ->orWhere('first_name', 'ilike', "%{$term}%")
                    ->orWhere('last_name', 'ilike', "%{$term}%")
                    ->orWhere('company_name', 'ilike', "%{$term}%")
                    ->orWhereHas('emails', fn ($email) => $email->where('email', 'ilike', "%{$term}%"));
            });
        });

        $members = $query->paginate(25)->withQueryString();

        return view('staff.members.index', compact('members', 'filters'));
    }

    public function member(Member $member): View
    {
        $member->load([
            'status', 'membershipPlan', 'organisation', 'emails', 'periods',
            'renewals.invoice.settlements', 'renewals.resultingPeriod',
            'invoices.settlements', 'payments.receipt', 'portalAccounts.user', 'portalInvitations', 'activeCredential',
        ]);

        return view('staff.members.show', compact('member'));
    }

    public function invitePortal(Request $request, Member $member): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'access_role' => ['required', Rule::in(['owner', 'representative', 'billing'])],
        ]);

        $result = $this->portal->issueInvitation($member, $validated['email'], $validated['access_role'], $request->user());
        $activationUrl = route('member.invitation', ['token' => $result['token']]);

        return back()->with('success', 'Portal invitation created.')->with('activation_url', $activationUrl);
    }

    public function renewals(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['invoiced', 'partial', 'settled', 'activated'])],
        ]);

        $query = MembershipRenewal::query()
            ->with(['member.status', 'member.membershipPlan', 'invoice.settlements', 'resultingPeriod'])
            ->latest('target_year')->latest('generated_at');
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $renewals = $query->paginate(30)->withQueryString();

        $expiring = Member::query()->notArchived()->active()->with(['membershipPlan', 'currentPeriod'])
            ->whereHas('periods', fn ($q) => $q->whereBetween('end_date', [today(), today()->copy()->addDays(60)]))
            ->limit(20)->get();

        return view('staff.renewals', compact('renewals', 'expiring', 'filters'));
    }

    public function createRenewal(Request $request, Member $member): RedirectResponse
    {
        $validated = $request->validate(['due_date' => ['nullable', 'date']]);
        $this->renewals->ensureRenewal(
            $member,
            $request->user(),
            isset($validated['due_date']) ? new \DateTimeImmutable($validated['due_date']) : null,
        );

        return back()->with('success', 'Renewal cycle and invoice prepared.');
    }

    public function recordRenewalPayment(Request $request, Member $member, MembershipRenewal $renewal): RedirectResponse
    {
        abort_unless((int) $renewal->member_id === (int) $member->id, 404);
        $validated = $this->validatePayment($request, false);

        $this->renewals->recordPayment(
            $renewal,
            (string) $validated['amount'],
            $validated['payment_method'],
            $validated['transaction_reference'],
            $request->user(),
            $validated['payment_provider'] ?? null,
            isset($validated['received_at']) ? new \DateTimeImmutable($validated['received_at']) : null,
        );

        return back()->with('success', 'Renewal payment recorded and membership lifecycle recalculated.');
    }

    public function finance(Request $request): View
    {
        $filters = $request->validate(['state' => ['nullable', Rule::in(['all', 'outstanding', 'overdue', 'paid'])]]);
        $state = $filters['state'] ?? 'all';

        $invoiceQuery = FinancialLedger::query()->invoices()->with(['member.status', 'application.plan', 'settlements'])->latest();
        if ($state === 'outstanding') {
            $invoiceQuery->outstanding();
        } elseif ($state === 'overdue') {
            $invoiceQuery->overdue();
        } elseif ($state === 'paid') {
            $invoiceQuery->whereNotNull('settled_at');
        }

        $invoices = $invoiceQuery->paginate(30)->withQueryString();
        $payments = FinancialLedger::query()->payments()->with(['member', 'application', 'receipt'])->latest('received_at')->limit(25)->get();
        $outstanding = FinancialLedger::query()->invoices()->outstanding()->with('settlements')->get();

        $summary = [
            'outstanding' => $outstanding->sum(fn (FinancialLedger $invoice): float => (float) $invoice->balance_due),
            'overdue_count' => FinancialLedger::query()->invoices()->overdue()->count(),
            'payments_month' => (float) FinancialLedger::query()->payments()->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('amount'),
            'receipts_total' => Receipt::query()->count(),
        ];

        return view('staff.finance', compact('invoices', 'payments', 'summary', 'state'));
    }

    public function receipt(Receipt $receipt): View
    {
        $receipt->load(['payment', 'application.plan', 'member.membershipPlan']);
        return view('staff.receipt', compact('receipt'));
    }

    public function organisations(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $query = Organisation::query()->withCount(['members', 'applications'])->latest();
        $query->when($filters['q'] ?? null, fn ($q, $term) => $q->where('legal_name', 'ilike', "%{$term}%"));
        $organisations = $query->paginate(25)->withQueryString();

        return view('staff.organisations', compact('organisations', 'filters'));
    }

    public function reports(): View
    {
        $members = Member::query()->notArchived()->with(['status', 'membershipPlan'])->get();
        $applications = MembershipApplication::query()->get();
        $renewals = MembershipRenewal::query()->with('invoice.settlements')->get();
        $invoices = FinancialLedger::query()->invoices()->with('settlements')->get();
        $payments = FinancialLedger::query()->payments()->whereYear('created_at', now()->year)->get();

        $report = [
            'members_total' => $members->count(),
            'member_statuses' => $members->groupBy(fn (Member $member) => $member->status?->code ?? 'UNKNOWN')->map->count()->sortDesc(),
            'member_plans' => $members->groupBy(fn (Member $member) => $member->membershipPlan?->name ?? 'Unclassified')->map->count()->sortDesc(),
            'application_statuses' => $applications->groupBy('status')->map->count()->sortDesc(),
            'renewal_statuses' => $renewals->groupBy('status')->map->count()->sortDesc(),
            'invoice_value' => (float) $invoices->sum('amount'),
            'outstanding_value' => $invoices->sum(fn (FinancialLedger $invoice): float => (float) $invoice->balance_due),
            'collections_year' => (float) $payments->sum('amount'),
            'monthly_collections' => $payments->groupBy(fn (FinancialLedger $payment) => ($payment->received_at ?? $payment->created_at)->format('M'))->map->sum('amount'),
        ];

        return view('staff.reports', compact('report'));
    }

    public function audit(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $query = AuditLog::query()->with('user')->latest();
        $query->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'ilike', "%{$action}%"));
        $query->when($filters['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId));
        $logs = $query->paginate(50)->withQueryString();

        return view('staff.audit', compact('logs', 'filters'));
    }

    private function applicationByReference(string $reference): MembershipApplication
    {
        return MembershipApplication::query()->where('reference', $reference)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validatePayment(Request $request, bool $requiresInvoice): array
    {
        $rules = [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::in(['bank_transfer', 'mobile_money', 'cash', 'card', 'cheque', 'other'])],
            'transaction_reference' => ['required', 'string', 'max:100', 'unique:financial_ledger,tx_reference'],
            'payment_provider' => ['nullable', 'string', 'max:80'],
            'received_at' => ['nullable', 'date'],
        ];
        if ($requiresInvoice) {
            $rules['invoice_id'] = ['required', 'integer', 'exists:financial_ledger,id'];
        }

        return $request->validate($rules);
    }
}
