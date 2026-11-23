<?php

namespace App\Domain;

use App\Enums\InvoiceDirection;
use App\Models\AccountRule;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Decimal;

/**
 * Ticket 09: imputazioni ricorrenti per azienda. Una regola vale per una controparte (codice fiscale/partita IVA), un ambito
 * (acquisti/vendite) e una condizione testuale sulla descrizione della riga; fornisce soltanto il conto.
 */
final class AccountRuleService
{
    public static function partyKey(Invoice $i): ?string
    {
        return $i->direction === InvoiceDirection::Vendita ? ($i->customer_vat ?? $i->customer_cf) : ($i->supplier_vat ?? $i->supplier_cf);
    }

    public static function scope(Invoice $i): ?string
    {
        return match ($i->direction) {
            InvoiceDirection::Acquisto => 'acquisti', InvoiceDirection::Vendita => 'vendite', default => null
        };
    }

    /**
     * Suggerimenti per ogni gruppo IVA (indice nel riepilogo):
     * single = tutte le righe del gruppo → un conto; split = righe → conti diversi (ripartizione secondo il ticket 04);
     * choice = una riga corrisponde a più regole con conti diversi, l'utente sceglie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggestions(Company $company, Invoice $invoice): array
    {
        $key = self::partyKey($invoice);
        $scope = self::scope($invoice);
        if (! $key || ! $scope) {
            return [];
        }
        $rules = AccountRule::where('company_id', $company->id)->where('party_key', $key)->where('scope', $scope)
            ->whereHas('account', fn ($q) => $q->where('active', true)->where('iva_rilevante', true))->with('account')->get();
        if ($rules->isEmpty()) {
            return [];
        }
        $invoice->loadMissing('vatSummaries', 'lines');
        $out = [];
        foreach ($invoice->vatSummaries->values() as $g => $s) {
            $lines = $invoice->lines->filter(fn ($l) => $l->natura === $s->natura && Decimal::toMinor((string) $l->rate) === Decimal::toMinor((string) $s->rate))->values();
            if ($lines->isEmpty()) {
                continue;
            }
            $perLine = [];
            foreach ($lines as $l) {
                $hits = $rules->filter(fn ($r) => mb_stripos((string) $l->description, $r->contains) !== false);
                $accounts = $hits->pluck('account_id')->unique()->values();
                if ($accounts->count() > 1) {
                    $out[$g] = ['type' => 'choice', 'line' => $l->description, 'accounts' => $accounts->all(),
                        'rules' => $hits->map(fn ($r) => "“{$r->contains}” → {$r->account->code}")->values()->all()];

                    continue 2;
                }
                $perLine[] = ['account_id' => $accounts->first(), 'total' => Decimal::toMinor((string) $l->total), 'rule' => $hits->first()];
            }
            if (collect($perLine)->contains(fn ($p) => $p['account_id'] === null)) {
                continue; // non tutte le righe riconosciute: nessun suggerimento di regola per il gruppo
            }
            $byAccount = collect($perLine)->groupBy('account_id');
            $ruleText = collect($perLine)->map(fn ($p) => "“{$p['rule']->contains}” → {$p['rule']->account->code}")->unique()->values()->all();
            if ($byAccount->count() === 1) {
                $out[$g] = ['type' => 'single', 'account_id' => (int) $byAccount->keys()->first(), 'rules' => $ruleText];

                continue;
            }
            // Ripartizione: base per conto dalle righe, IVA in proporzione, resto sull'ultima parte (al centesimo).
            $gt = Decimal::toMinor((string) $s->taxable);
            $gv = Decimal::toMinor((string) $s->tax);
            if ($invoice->doc_type === 'TD04') {
                $gt = abs($gt);
                $gv = abs($gv);
            }
            $sumLines = array_sum(array_column($perLine, 'total'));
            if (abs($sumLines) !== $gt) {
                continue; // le righe non corrispondono al gruppo (es. sconti): nessun suggerimento di ripartizione
            }
            $parts = [];
            $restT = $gt;
            $restV = $gv;
            $n = $byAccount->count();
            foreach ($byAccount->values() as $k => $items) {
                $t = abs($items->sum('total'));
                $v = $gt ? intdiv($gv * $t * 2 + $gt, 2 * $gt) : 0;
                if ($k === $n - 1) {
                    [$t, $v] = [$restT, $restV];
                }
                $restT -= $t;
                $restV -= $v;
                $parts[] = ['account_id' => (int) $items->first()['account_id'], 'taxable' => Decimal::fromMinor($t), 'tax' => Decimal::fromMinor($v)];
            }
            $out[$g] = ['type' => 'split', 'parts' => $parts, 'rules' => $ruleText];
        }

        return $out;
    }

    /** Salva le regole confermate durante la registrazione (i duplicati vengono ignorati). */
    public function remember(Company $company, Invoice $invoice, User $actor, array $rules): int
    {
        $key = self::partyKey($invoice);
        $scope = self::scope($invoice);
        if (! $key || ! $scope) {
            return 0;
        }
        $party = $invoice->direction === InvoiceDirection::Vendita ? $invoice->customer_name : $invoice->supplier_name;
        $n = 0;
        foreach ($rules as $r) {
            $contains = mb_strtolower(trim((string) ($r['contains'] ?? '')));
            $account = $company->accounts()->whereKey((int) ($r['account_id'] ?? 0))->first();
            if ($contains === '' || ! $account) {
                continue;
            }
            $rule = AccountRule::firstOrCreate(['company_id' => $company->id, 'party_key' => $key, 'scope' => $scope, 'contains' => mb_substr($contains, 0, 100), 'account_id' => $account->id],
                ['party_name' => $party, 'created_by' => $actor->id]);
            if ($rule->wasRecentlyCreated) {
                $n++;
                Audit::record('account_rule.created', $actor, $company, $rule, data: ['party' => $party, 'contains' => $contains, 'account' => $account->code, 'scope' => $scope]);
            }
        }

        return $n;
    }
}
