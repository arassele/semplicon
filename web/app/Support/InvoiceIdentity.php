<?php

namespace App\Support;

/**
 * Ticket 08: identità sostanziale e contenuto di una fattura.
 * Candidato al conflitto = stesso emittente (cedente), tipo di documento, anno e numero.
 * Confronto del contenuto sui valori letti (non sulla formattazione XML): stessi valori → «Già presente».
 */
final class InvoiceIdentity
{
    /** @param array{name: string, vat: ?string, cf: ?string} $issuer */
    public static function key(array $issuer, string $docType, string $date, string $number): string
    {
        return hash('sha256', implode('|', [
            $issuer['vat'] ?? $issuer['cf'] ?? mb_strtoupper(trim($issuer['name'])), $docType, substr($date, 0, 4), mb_strtoupper(trim($number)),
        ]));
    }

    /**
     * @param  array{doc_type: string, number: string, date: string, currency: string, total_amount: ?string, supplier: array, customer: array, summaries: list<array>, lines: list<array>}  $d
     */
    public static function businessHash(array $d): string
    {
        $n = fn ($v) => $v === null || $v === '' ? null : Decimal::normalize((string) $v);
        $t = fn ($v) => $v === null ? null : preg_replace('/\s+/u', ' ', trim((string) $v));
        $party = fn ($p) => [$t($p['vat'] ?? null), $t($p['cf'] ?? null), mb_strtoupper((string) $t($p['name'] ?? ''))];

        return hash('sha256', json_encode([
            $d['doc_type'], mb_strtoupper(trim($d['number'])), $d['date'], $d['currency'], $n($d['total_amount']),
            $party($d['supplier']), $party($d['customer']),
            array_map(fn ($s) => [$n($s['rate']), $s['natura'] ?? null, $n($s['taxable']), $n($s['tax'])], $d['summaries']),
            array_map(fn ($l) => [(int) ($l['line_no'] ?? 0), $t($l['description'] ?? ''), $n($l['quantity'] ?? null), $n($l['unit_price'] ?? null), $n($l['total'] ?? null), $n($l['rate'] ?? null), $l['natura'] ?? null], $d['lines']),
        ]));
    }
}
