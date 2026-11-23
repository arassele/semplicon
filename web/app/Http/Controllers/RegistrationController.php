<?php

namespace App\Http\Controllers;

use App\Domain\RegistrationService;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\VatRegistration;
use App\Support\Decimal;
use App\Support\VatPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function __construct(private RegistrationService $service) {}

    private function rules(Request $request): array
    {
        return $request->validate([
            'direction' => ['nullable', 'in:acquisto,vendita'],
            'receipt_date' => ['nullable', 'date_format:Y-m-d'],
            'registration_date' => ['required', 'date_format:Y-m-d'],
            'year' => ['required', 'integer'],
            'period' => ['required', 'integer'],
            'lines' => ['required', 'array'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.deductible_pct' => ['nullable', 'string', 'max:6'],
            'acknowledged' => ['array'],
            'acknowledged.*' => ['string'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'next' => ['boolean'],
            'request_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'lines.*.summary' => ['nullable', 'integer', 'min:0'],
            'lines.*.taxable' => ['nullable', 'string', 'max:20'],
            'lines.*.tax' => ['nullable', 'string', 'max:20'],
            'rules' => ['nullable', 'array', 'max:20'],
            'rules.*.contains' => ['required', 'string', 'min:2', 'max:100'],
            'rules.*.account_id' => ['required', 'integer'],
        ], [], ['registration_date' => 'data di registrazione', 'year' => 'anno', 'period' => 'periodo']);
    }

    public function store(Request $request, Company $company, Invoice $invoice): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage', $company);
        $data = $this->rules($request);
        $registration = $this->service->register($company, $invoice, $request->user(), $data);
        $info = $this->confirmation($company, $registration);
        $msg = "Registrata con protocollo {$registration->protocol}/{$registration->year}.";
        // Ticket 07: conferma del documento appena completato anche nella pagina successiva.
        $request->session()->flash('registered', $info);

        $nextUrl = null;
        if ($request->boolean('next')) {
            $next = $this->service->nextOpen($company, $invoice->id);
            $nextUrl = $next ? route('invoices.show', [$company, $next, 'verifica' => 1], false) : route('invoices.index', [$company, 'stato' => 'aperte'], false);
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'replayed' => $registration->replayed, 'registration' => $info, 'next_url' => $nextUrl ?? route('invoices.show', [$company, $invoice], false)]);
        }

        return $nextUrl ? redirect($nextUrl)->with('success', $msg.($next ? ' Prossimo documento.' : ' Non ci sono altri documenti da registrare.')) : back()->with('success', $msg);
    }

    /** Ticket 05: righe di registro preparate senza registrare (stessa logica della registrazione). */
    public function preview(Request $request, Company $company, Invoice $invoice): JsonResponse
    {
        $data = $this->rules($request);
        try {
            $p = $this->service->prepare($company, $invoice, $data, forEdit: $request->boolean('editing'));
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()]);
        }
        $m = fn (int $c) => Decimal::fromMinor($c * $p['sign']);

        return response()->json(['ok' => true, 'sign' => $p['sign'], 'period_label' => $p['period_label'],
            'registration_date' => $p['registration_date'], 'receipt_date' => $p['receipt_date'],
            'register' => $p['direction']->value === 'acquisto' ? 'Registro acquisti' : 'Registro vendite',
            'lines' => array_map(fn ($l) => ['account' => $l['account_label'], 'rate' => $l['rate'], 'natura' => $l['natura'], 'taxable' => $m($l['taxable']), 'tax' => $m($l['tax']),
                'deductible_pct' => $l['deductible_pct'], 'deductible' => $m($l['deductible']), 'non_deductible' => $m($l['non_deductible'])], $p['lines']),
            'totals' => array_map($m, $p['totals'])]);
    }

    /** @return array{id: int, invoice: string, register: string, protocol: string, period: string, url: string} */
    private function confirmation(Company $company, VatRegistration $r): array
    {
        $r->loadMissing('invoice');
        $register = $r->direction->value === 'acquisto' ? 'acquisti' : 'vendite';

        return ['id' => $r->id, 'invoice' => $r->invoice->number, 'register' => $register === 'acquisti' ? 'Registro acquisti' : 'Registro vendite',
            'protocol' => "{$r->protocol}/{$r->year}", 'period' => VatPeriod::label($r->year, $r->period, $r->periodicity),
            'url' => "/aziende/{$company->id}/registri/{$register}?anno={$r->year}&periodo={$r->period}&reg={$r->id}#reg-{$r->id}"];
    }

    /** Modificare la registrazione (indicazione del committente, 19.11.2026). */
    public function update(Request $request, Company $company, VatRegistration $vatRegistration): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage', $company);
        $r = $this->service->update($company, $vatRegistration, $request->user(), $this->rules($request));
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'registration' => $this->confirmation($company, $r), 'next_url' => route('invoices.show', [$company, $r->invoice_id], false)]);
        }

        return back()->with('success', "Registrazione {$r->protocol}/{$r->year} modificata.");
    }

    /** Eliminare la registrazione; la fattura torna «da registrare». */
    public function destroy(Request $request, Company $company, VatRegistration $vatRegistration): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $label = "{$vatRegistration->protocol}/{$vatRegistration->year}";
        $this->service->delete($company, $vatRegistration, $request->user());

        return back()->with('success', "Registrazione {$label} eliminata: la fattura è di nuovo da registrare.");
    }

    public function reverse(Request $request, Company $company, VatRegistration $vatRegistration): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['reason' => 'motivo', 'date' => 'data dello storno']);
        $storno = $this->service->reverse($company, $vatRegistration, $request->user(), $data['reason'], $data['date'] ?? null);
        $period = VatPeriod::label($storno->year, $storno->period, $storno->periodicity);

        return back()->with('success', "Registrazione stornata: storno protocollo {$storno->protocol}/{$storno->year}, periodo {$period}. La fattura è di nuovo da registrare.");
    }

    public function discard(Request $request, Company $company, Invoice $invoice): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motivo']);
        $this->service->discard($company, $invoice, $request->user(), $data['reason']);

        if ($request->boolean('next') && ($next = $this->service->nextOpen($company, $invoice->id))) {
            return redirect()->route('invoices.show', [$company, $next, 'verifica' => 1])->with('success', 'Fattura scartata. Prossimo documento.');
        }

        return back()->with('success', 'Fattura scartata.');
    }

    public function restore(Request $request, Company $company, Invoice $invoice): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->service->restore($company, $invoice, $request->user());

        return back()->with('success', 'Fattura ripristinata.');
    }

    /** Avviare la modalità di controllo: prima fattura aperta. */
    public function start(Company $company): RedirectResponse
    {
        $next = $this->service->nextOpen($company);

        return $next
            ? redirect()->route('invoices.show', [$company, $next, 'verifica' => 1])
            : redirect()->route('invoices.index', [$company, 'stato' => 'aperte'])->with('success', 'Non ci sono documenti da registrare.');
    }
}
