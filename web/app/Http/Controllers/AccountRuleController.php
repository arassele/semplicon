<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Models\AccountRule;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Tabelle › Regole di contabilizzazione (Ticket 09). */
class AccountRuleController extends Controller
{
    public function index(Company $company): Response
    {
        return Inertia::render('Tables/Rules', [
            'rules' => AccountRule::where('company_id', $company->id)->with('account:id,code,name')->orderBy('party_name')->orderBy('contains')->get()
                ->map(fn ($r) => ['id' => $r->id, 'party' => $r->party_name, 'party_key' => $r->party_key, 'scope' => $r->scope, 'contains' => $r->contains,
                    'account' => $r->account->code.' · '.$r->account->name, 'created_at' => $r->created_at->toIso8601String()]),
        ]);
    }

    public function destroy(Request $request, Company $company, int $rule): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $r = AccountRule::where('company_id', $company->id)->findOrFail($rule);
        $r->delete();
        Audit::record('account_rule.deleted', $request->user(), $company, data: ['party' => $r->party_name, 'contains' => $r->contains]);

        return back()->with('success', 'Regola eliminata.');
    }
}
