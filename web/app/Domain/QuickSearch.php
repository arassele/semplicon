<?php

namespace App\Domain;

use App\Models\Company;
use App\Models\Document;
use App\Support\Decimal;

/** Ricerca rapida (Ctrl+K, elenco requisiti 9.4): fatture, controparti, catalogo, fatture ricorrenti e documenti di un'azienda. */
final class QuickSearch
{
    /** @return list<array{kind: string, title: string, subtitle: ?string, url: string}> */
    public function search(Company $company, string $term): array
    {
        $term = mb_substr(trim($term), 0, 60);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($term)).'%';
        $base = "/aziende/{$company->id}";
        $out = [];

        $invoices = $company->invoices()->where(fn ($w) => $w
            ->whereRaw('LOWER(number) LIKE ?', [$like])->orWhereRaw('LOWER(supplier_name) LIKE ?', [$like])->orWhereRaw('LOWER(customer_name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(supplier_vat) LIKE ?', [$like])->orWhereRaw('LOWER(customer_vat) LIKE ?', [$like])->orWhereRaw('LOWER(supplier_cf) LIKE ?', [$like])
            ->orWhereRaw('LOWER(customer_cf) LIKE ?', [$like])->orWhereRaw('LOWER(description) LIKE ?', [$like]))
            ->orderByDesc('doc_date')->orderByDesc('id')->limit(40)->get();
        foreach ($invoices->take(8) as $i) {
            $sale = $i->direction?->value === 'vendita';
            $out[] = ['kind' => 'fattura', 'title' => ($i->doc_type === 'TD04' ? 'Nota di credito' : 'Fattura').' n. '.$i->number.' del '.$i->doc_date->format('d/m/Y'),
                'subtitle' => ($sale ? 'Vendita' : 'Acquisto').' · '.($sale ? $i->customer_name : $i->supplier_name).($i->total_amount !== null ? ' · '.str_replace('.', ',', Decimal::normalize((string) $i->total_amount)).' €' : ''), 'url' => "{$base}/fatture/{$i->id}"];
        }
        // Controparti: nomi univoci dai risultati; porta all'elenco filtrato su tutti gli anni
        $seen = [];
        foreach ($invoices as $i) {
            $sale = $i->direction?->value === 'vendita';
            $name = (string) ($sale ? $i->customer_name : $i->supplier_name);
            $vat = (string) ($sale ? $i->customer_vat : $i->supplier_vat);
            $key = mb_strtolower($vat ?: $name);
            if ($name === '' || isset($seen[$key]) || count($seen) >= 5 || ! (str_contains(mb_strtolower($name), mb_strtolower($term)) || str_contains(mb_strtolower($vat), mb_strtolower($term)))) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['kind' => 'controparte', 'title' => $name, 'subtitle' => ($sale ? 'Cliente' : 'Fornitore').($vat ? " · {$vat}" : ''), 'url' => "{$base}/fatture?q=".rawurlencode($name).'&anno=tutti'];
        }
        foreach ($company->catalogArticles()->where(fn ($w) => $w->whereRaw('LOWER(description) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]))->orderBy('description')->limit(5)->get() as $a) {
            $out[] = ['kind' => 'articolo', 'title' => ($a->code ? "{$a->code} · " : '').$a->description, 'subtitle' => 'Articolo · '.str_replace('.', ',', (string) $a->price).' €', 'url' => "{$base}/tabelle/catalogo"];
        }
        foreach ($company->catalogSnippets()->where(fn ($w) => $w->whereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(body) LIKE ?', [$like]))->limit(3)->get() as $s) {
            $out[] = ['kind' => 'testo', 'title' => $s->title, 'subtitle' => 'Testo predefinito · '.($s->kind === 'causale' ? 'causale' : 'riga'), 'url' => "{$base}/tabelle/catalogo"];
        }
        foreach ($company->recurringInvoices()->whereRaw('LOWER(name) LIKE ?', [$like])->limit(3)->get() as $r) {
            $out[] = ['kind' => 'ricorrente', 'title' => $r->name, 'subtitle' => 'Fattura ricorrente · '.$r->frequency, 'url' => "{$base}/fatture/ricorrenti"];
        }
        foreach (Document::where('company_id', $company->id)->whereRaw('LOWER(original_name) LIKE ?', [$like])->orderByDesc('id')->limit(4)->get() as $d) {
            $out[] = ['kind' => 'documento', 'title' => $d->original_name, 'subtitle' => 'Documento originale', 'url' => "{$base}/documenti/{$d->id}/scarica"];
        }

        return $out;
    }
}
