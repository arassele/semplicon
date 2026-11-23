<?php

namespace App\Support;

use App\Models\AtecoCode;

/** Codici ATECO 2025 dalla tabella ateco_codes (fonte ISTAT, CC BY 4.0; gestibile dall'amministratore). */
final class Ateco
{
    public const SOURCE = 'Fonte: ISTAT – Classificazione ATECO 2025 (CC BY 4.0)';

    /** Solo codici attivi; formato come nell'elenco ISTAT. */
    public static function find(string $code): ?array
    {
        $row = AtecoCode::where('code', trim($code))->where('active', true)->first();

        return $row ? ['code' => $row->code, 'title_it' => $row->title_it] : null;
    }

    /** @return array<string, array{code: string, title_it: string}> */
    public static function all(): array
    {
        return AtecoCode::where('active', true)->orderBy('code')->get()
            ->mapWithKeys(fn ($r) => [$r->code => ['code' => $r->code, 'title_it' => $r->title_it]])->all();
    }

    /** Ricerca per inizio del codice o per parole nel titolo. */
    public static function search(string $q, int $limit = 15, bool $onlyActive = true): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return [];
        }
        $query = AtecoCode::query()->when($onlyActive, fn ($w) => $w->where('active', true));
        $digits = str_replace('.', '', $q);
        if (preg_match('/^[\d.]+$/', $q)) {
            // Ricerca per codice, con o senza punti.
            $query->where(fn ($w) => $w->where('code', 'like', $q.'%')->orWhereRaw("REPLACE(code, '.', '') LIKE ?", [$digits.'%']));
        } else {
            foreach (preg_split('/\s+/', $q) as $word) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
                $query->where(fn ($w) => $w->whereRaw('LOWER(title_it) LIKE ?', [$like]));
            }
        }

        return $query->orderBy('code')->limit($limit)->get()
            ->map(fn ($r) => ['code' => $r->code, 'title_it' => $r->title_it, 'active' => $r->active])->all();
    }
}
