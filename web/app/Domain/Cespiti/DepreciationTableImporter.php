<?php

namespace App\Domain\Cespiti;

use App\Models\DepreciationRate;
use App\Models\DepreciationTable;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tabelle normative (immutabili): importazione di una tabella da CSV (gruppo;gruppo_descrizione;specie;specie_descrizione;descrizione;coefficiente;da_verificare).
 * Fornita: D.M. 31.12.1988 (Testo vigente dal 16/05/1996, mod. D.M. 28/03/1996), estratta dalla versione PDF fornita dal committente
 * (database/data/dm_1988_fonte.pdf → dm_1988_coefficienti.csv). L'ultima sezione «Attività non precedentemente specificate» non ha nel decreto
 * numero di gruppo e viene gestita internamente come Gruppo 23 (Specie 1 Fiere e rassegne, 2 Altre attività). Una tabella esistente con lo stesso codice non viene mai sovrascritta: nuova versione = nuovo codice.
 */
final class DepreciationTableImporter
{
    public const DM1988 = 'DM-1988';

    public static function importBundled(): ?DepreciationTable
    {
        if (DepreciationTable::where('code', self::DM1988)->exists()) {
            return null;
        }
        $file = database_path('data/dm_1988_coefficienti.csv');

        return self::import($file, [
            'code' => self::DM1988, 'title' => 'D.M. 31 dicembre 1988 – Coefficienti di ammortamento del costo dei beni materiali strumentali',
            'source' => 'Gazzetta Ufficiale n. 27 del 2 febbraio 1989; tabella vigente dal 16/05/1996 (modificata dal D.M. 28/03/1996 n. 150247, art. 1). Estratta dal PDF fornito (dm_1988_fonte.pdf).',
            'version' => 'testo vigente 16/05/1996', 'effective_from' => '1996-05-16',
            'notes' => 'Le righe con “da verificare” hanno un testo incompleto nella fonte. Per arti e professioni valgono i coefficienti dei beni corrispondenti o similari (art. 2). I beni immateriali (art. 103 TUIR) non sono in questa tabella.',
        ]);
    }

    public static function import(string $file, array $meta): DepreciationTable
    {
        $rows = self::read($file);

        return DB::transaction(function () use ($rows, $meta, $file) {
            $t = DepreciationTable::create([...$meta, 'source_sha256' => hash_file('sha256', $file)]);
            foreach ($rows as $k => $x) {
                DepreciationRate::create(['depreciation_table_id' => $t->id, ...self::attrs($x, $k)]);
            }

            return $t;
        });
    }

    /**
     * Correzione di un errore di estrazione (nessun cambio normativo, decisione n. 80): le righe della tabella vengono allineate al
     * CSV corretto. Gli ID delle righe esistenti restano, dove la voce (descrizione + coefficiente) viene ritrovata –
     * prima nello stesso Gruppo/Specie, poi altrove (righe assegnate in modo errato). Le righe non ritrovate (testo accorpato)
     * vengono eliminate; le categorie mantengono le proprie aliquote, viene meno solo il riferimento alla voce.
     *
     * @return array{kept:int, moved:int, added:int, removed:int}
     */
    public static function resync(DepreciationTable $t, string $file, ?string $note = null): array
    {
        $rows = self::read($file);

        return DB::transaction(function () use ($t, $rows, $file, $note) {
            $old = DepreciationRate::where('depreciation_table_id', $t->id)->orderBy('position')->get()->keyBy('id');
            $key = fn ($desc, $coef) => $desc.'|'.Decimal::fromMinor(Decimal::toMinor((string) $coef));
            $match = array_fill(0, count($rows), null);
            $used = [];
            foreach ([true, false] as $strict) {
                foreach ($rows as $k => $x) {
                    if ($match[$k] !== null) {
                        continue;
                    }
                    $a = self::attrs($x, $k);
                    $hit = $old->first(fn ($r) => ! isset($used[$r->id]) && $key($r->description, $r->coefficient) === $key($a['description'], $a['coefficient'])
                        && (! $strict || ((int) $r->gruppo === $a['gruppo'] && $r->specie === $a['specie'])));
                    if ($hit) {
                        $match[$k] = [$hit->id, $strict];
                        $used[$hit->id] = true;
                    }
                }
            }
            $n = ['kept' => 0, 'moved' => 0, 'added' => 0, 'removed' => 0];
            $removed = $old->keys()->diff(array_keys($used))->all();
            if ($removed) {
                DepreciationRate::whereIn('id', $removed)->delete();
                $n['removed'] = count($removed);
            }
            foreach ($rows as $k => $x) {
                $a = self::attrs($x, $k);
                if ($match[$k] === null) {
                    DepreciationRate::create(['depreciation_table_id' => $t->id, ...$a]);
                    $n['added']++;
                } else {
                    DepreciationRate::whereKey($match[$k][0])->update($a);
                    $n[$match[$k][1] ? 'kept' : 'moved']++;
                }
            }
            $t->update(['source_sha256' => hash_file('sha256', $file), 'notes' => $note ? trim(($t->notes ?? '').' '.$note) : $t->notes]);

            return $n;
        });
    }

    /** @return list<array<string, string>> */
    private static function read(string $file): array
    {
        $fh = @fopen($file, 'r');
        if (! $fh) {
            throw new RuntimeException("File non leggibile: {$file}");
        }
        $head = fgetcsv($fh, null, ';', '"', '');
        $need = ['gruppo', 'gruppo_descrizione', 'specie', 'specie_descrizione', 'descrizione', 'coefficiente'];
        if (! $head || array_diff($need, $head)) {
            throw new RuntimeException('Intestazione non valida: servono le colonne '.implode(', ', $need).'.');
        }
        $rows = [];
        while (($r = fgetcsv($fh, null, ';', '"', '')) !== false) {
            if (count($r) < count($head)) {
                continue;
            }
            $x = array_combine($head, array_slice($r, 0, count($head)));
            $coef = str_replace(',', '.', trim($x['coefficiente']));
            if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $coef) || (float) $coef > 100) {
                throw new RuntimeException("Coefficiente non valido: {$x['coefficiente']}");
            }
            $rows[] = $x + ['coef' => $coef];
        }
        fclose($fh);

        return $rows;
    }

    private static function attrs(array $x, int $k): array
    {
        return ['gruppo' => (int) $x['gruppo'], 'gruppo_desc' => $x['gruppo_descrizione'], 'specie' => $x['specie'] !== '' ? $x['specie'] : null,
            'specie_desc' => $x['specie_descrizione'] ?: null, 'description' => $x['descrizione'], 'coefficient' => Decimal::fromMinor(Decimal::toMinor($x['coef'])),
            'verify_note' => ($x['da_verificare'] ?? '') ?: null, 'position' => $k + 1];
    }
}
