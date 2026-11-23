<?php

namespace App\Domain;

use App\Enums\CompanyType;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Piano dei conti per azienda: modello 2026 (semplificata; ordinaria salvata) con dati fiscali, liberamente modificabile. */
final class AccountService
{
    /**
     * Modelli con conti standard per tipo (suggerimento nella registrazione). L'ordinaria è salvata
     * e viene abilitata non appena Semplicon supporterà la contabilità ordinaria.
     */
    public const MODELS = [
        'semplificata' => ['file' => 'piano_conti_semplificata_2026.csv', 'label' => 'Contabilità semplificata 2026', 'available' => true, 'defaults' => ['ricavo' => '701003', 'costo' => '602026']],
        'professionisti' => ['file' => 'piano_conti_professionisti_2026.csv', 'label' => 'Contabilità professionisti 2026 (quadro RE)', 'available' => true, 'defaults' => ['ricavo' => '711001', 'costo' => '641009']],
        'patrimoniale' => ['file' => 'piano_conti_patrimoniale_2026.csv', 'label' => 'Stato patrimoniale cespiti', 'available' => false, 'defaults' => []],
        'ordinaria' => ['file' => 'piano_conti_ordinaria_2026.csv', 'label' => 'Contabilità ordinaria 2026', 'available' => false, 'defaults' => ['ricavo' => '701003', 'costo' => '602026']],
    ];

    public const DEFAULT_MODEL = 'semplificata';

    public const PATRIMONIALE = 'patrimoniale';

    private static array $models = [];

    /** @return list<array<string, mixed>> Conti da database/data/piano_conti_{model}_2026.csv */
    public static function model(string $key = self::DEFAULT_MODEL): array
    {
        if (isset(self::$models[$key])) {
            return self::$models[$key];
        }
        $fh = fopen(database_path('data/'.self::MODELS[$key]['file']), 'r');
        $head = fgetcsv($fh, null, ';', '"', '');
        $rows = [];
        while (($r = fgetcsv($fh, null, ';', '"', '')) !== false) {
            $x = array_combine($head, $r);
            $rows[] = [
                'code' => $x['codice'], 'name' => $x['descrizione'], 'type' => $x['tipo'], 'sezione' => ($x['sezione'] ?? '') ?: null,
                'voce_cee' => $x['voce_cee'] ?: null, 'categoria' => $x['categoria'] ?: null, 'iva_rilevante' => $x['iva_rilevante'] === '1',
                'iva_detraibile_pct' => $x['iva_detraibile_pct'] !== '' ? $x['iva_detraibile_pct'] : null, 'iva_nota' => $x['iva_nota'] ?: null,
                'deducibilita_pct' => $x['deducibilita_pct'] !== '' ? $x['deducibilita_pct'] : null, 'deducibilita_nota' => $x['deducibilita_nota'] ?: null,
                'irap_nota' => $x['irap_nota'] ?: null, 'limite' => $x['limite'] ?: null, 'note' => $x['note'] ?: null, 'normativa' => $x['normativa'] ?: null,
                'is_default' => (self::MODELS[$key]['defaults'][$x['tipo']] ?? null) === $x['codice'],
            ];
        }
        fclose($fh);
        // Stato patrimoniale per il modulo Cespiti (decisione n. 79): Immobilizzazioni, Fondi e Cessione, uguali per ogni modello.
        if ($key !== self::PATRIMONIALE) {
            $rows = [...$rows, ...self::model(self::PATRIMONIALE)];
        }

        return self::$models[$key] = $rows;
    }

    /** I professionisti (Tipologia) ricevono il modello per il quadro RE, tutti gli altri quello della semplificata. */
    public static function modelFor(Company $company): string
    {
        return $company->tipologia === CompanyType::Professionista ? 'professionisti' : self::DEFAULT_MODEL;
    }

    /** Conti non necessari alle persone fisiche: l'IRAP dal 2022 non è dovuta da imprese individuali e liberi professionisti. */
    public const NOT_FOR_PERSONS = ['951002'];

    /**
     * Righe del modello per questa azienda: per le persone fisiche (ditta individuale, professionista) il conto IRAP viene meno
     * e l'indicazione IRAP riporta «Non dovuta (persona fisica)»; le società mantengono le indicazioni del modello.
     *
     * @return list<array<string, mixed>>
     */
    public static function rowsFor(Company $company, string $key): array
    {
        if (! $company->tipologia?->isPerson()) {
            return self::model($key);
        }

        return array_values(array_map(fn ($r) => [...$r, 'irap_nota' => 'Non dovuta (persona fisica)'],
            array_filter(self::model($key), fn ($r) => ! in_array($r['code'], self::NOT_FOR_PERSONS, true))));
    }

    public function ensureDefaults(Company $company): void
    {
        if ($company->accounts()->exists()) {
            return;
        }
        foreach (self::rowsFor($company, self::modelFor($company)) as $row) {
            Account::create([...$row, 'company_id' => $company->id, 'source' => 'modello']);
        }
    }

    public function create(Company $company, User $actor, array $data): Account
    {
        return DB::transaction(function () use ($company, $actor, $data) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $data = $this->normalize($data);
            $this->checkCode($company, $data['code']);
            $account = Account::create([...$data, 'company_id' => $company->id, 'is_default' => false, 'source' => 'manuale']);
            Audit::record('accounts.updated', $actor, $company, $account, data: ['added' => $account->code]);

            return $account;
        });
    }

    public function update(Company $company, Account $account, User $actor, array $data): Account
    {
        return DB::transaction(function () use ($company, $account, $actor, $data) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $data = $this->normalize($data);
            $this->checkCode($company, $data['code'], $account->id);
            if ($data['type'] !== $account->type && $account->isUsed()) {
                RuleViolation::fail(['type' => 'Il conto è già usato in registrazioni: il tipo non si può cambiare.']);
            }
            if ($account->is_default && $data['type'] !== $account->type) {
                RuleViolation::fail(['type' => 'Il conto è predefinito: scegli prima un altro conto predefinito.']);
            }
            if ($account->is_default && (! $data['active'] || ! $data['iva_rilevante'])) {
                RuleViolation::fail(['active' => 'Il conto predefinito deve restare attivo e rilevante ai fini IVA: scegli prima un altro conto predefinito.']);
            }
            $before = $account->only(['code', 'name', 'type', 'active', ...Account::FISCAL]);
            $account->update($data);
            $changed = array_keys(array_diff_assoc(array_map('strval', $account->only(array_keys($before))), array_map('strval', $before)));
            Audit::record('accounts.updated', $actor, $company, $account, data: ['changed' => $account->code, 'fields' => $changed]);

            return $account;
        });
    }

    public function delete(Company $company, Account $account, User $actor): void
    {
        if ($account->isUsed()) {
            RuleViolation::fail(['account' => "Il conto {$account->code} è usato in registrazioni: puoi solo disattivarlo."]);
        }
        if ($account->is_default) {
            RuleViolation::fail(['account' => 'Il conto predefinito non si può eliminare: scegli prima un altro conto predefinito.']);
        }
        $code = $account->code;
        $account->delete();
        Audit::record('accounts.updated', $actor, $company, data: ['removed' => $code]);
    }

    public function makeDefault(Company $company, Account $account, User $actor): void
    {
        if (! $account->active || ! $account->iva_rilevante) {
            RuleViolation::fail(['account' => 'Il conto predefinito deve essere attivo e rilevante ai fini IVA.']);
        }
        DB::transaction(function () use ($company, $account) {
            $company->accounts()->where('type', $account->type)->where('id', '!=', $account->id)->update(['is_default' => false]);
            $account->update(['is_default' => true]);
        });
        Audit::record('accounts.updated', $actor, $company, $account, data: ['default' => $account->code]);
    }

    /**
     * Recepisce il modello 2026: i codici mancanti vengono creati, i conti esistenti restano invariati.
     * Facoltativamente vengono eliminati i conti non utilizzati al di fuori del modello.
     *
     * @return array{added: int, removed: int}
     */
    public function applyModel(Company $company, User $actor, bool $removeOthers, string $key = self::DEFAULT_MODEL): array
    {
        if (! (self::MODELS[$key]['available'] ?? false)) {
            RuleViolation::fail(['model' => 'Questo modello sarà disponibile quando Semplicon gestirà la contabilità ordinaria.']);
        }

        return DB::transaction(function () use ($company, $actor, $removeOthers, $key) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $existing = $company->accounts()->get()->keyBy('code');
            $model = collect(self::rowsFor($company, $key))->keyBy('code');
            $added = $removed = 0;
            foreach ($model as $code => $row) {
                if (! $existing->has($code)) {
                    Account::create([...$row, 'company_id' => $company->id, 'is_default' => false, 'source' => 'modello']);
                    $added++;
                }
            }
            if ($removeOthers) {
                foreach ($existing as $code => $account) {
                    if (! $model->has($code) && ! $account->isUsed()) {
                        $account->delete();
                        $removed++;
                    }
                }
            }
            // Per ogni tipo esattamente un conto standard attivo; se manca, vale quello del modello.
            foreach (self::MODELS[$key]['defaults'] as $type => $code) {
                $hasDefault = $company->accounts()->where('type', $type)->where('is_default', true)->where('active', true)->where('iva_rilevante', true)->exists();
                if (! $hasDefault) {
                    $company->accounts()->where('type', $type)->update(['is_default' => false]);
                    $company->accounts()->where('code', $code)->update(['is_default' => true, 'active' => true]);
                }
            }
            Audit::record('accounts.updated', $actor, $company, data: ['model' => $key.' 2026', 'added' => $added, 'removed' => $removed]);

            return ['added' => $added, 'removed' => $removed];
        });
    }

    private function checkCode(Company $company, string $code, ?int $ignoreId = null): void
    {
        if ($company->accounts()->where('code', $code)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            RuleViolation::fail(['code' => "Il codice {$code} esiste già nel piano dei conti."]);
        }
    }

    private function normalize(array $d): array
    {
        $pct = fn ($v) => ($v === null || $v === '') ? null : Decimal::normalize(str_replace(',', '.', (string) $v));
        $txt = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);

        return [
            'code' => trim((string) $d['code']), 'name' => trim((string) $d['name']), 'type' => $d['type'],
            'active' => (bool) ($d['active'] ?? true), 'iva_rilevante' => (bool) ($d['iva_rilevante'] ?? true),
            'sezione' => $txt($d['sezione'] ?? null), 'voce_cee' => $txt($d['voce_cee'] ?? null), 'categoria' => $txt($d['categoria'] ?? null),
            'iva_detraibile_pct' => $pct($d['iva_detraibile_pct'] ?? null), 'iva_nota' => $txt($d['iva_nota'] ?? null),
            'deducibilita_pct' => $pct($d['deducibilita_pct'] ?? null), 'deducibilita_nota' => $txt($d['deducibilita_nota'] ?? null),
            'irap_nota' => $txt($d['irap_nota'] ?? null), 'limite' => $txt($d['limite'] ?? null), 'normativa' => $txt($d['normativa'] ?? null), 'note' => $txt($d['note'] ?? null),
        ];
    }
}
