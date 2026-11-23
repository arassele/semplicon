<?php

namespace App\Domain;

use App\Models\Company;
use App\Models\User;
use App\Models\ViesCheck;
use App\Support\Validation\PartitaIva;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifica di partite IVA UE tramite VIES (elenco requisiti 5.2, adattato: il sistema informativo UE al posto del BZSt). Con la propria partita IVA come
 * richiedente VIES fornisce un numero di richiesta (requestIdentifier), che viene conservato come prova per le cessioni intracomunitarie non imponibili.
 * I numeri italiani ricevono prima il controllo della cifra di controllo. «Non raggiungibile» non è un'indicazione sul numero.
 */
final class ViesService
{
    /** Sigla del paese VIES (Grecia: EL, Irlanda del Nord: XI). */
    public const COUNTRIES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];

    /** @return array{country: string, vat: string} */
    public static function normalize(string $country, string $vat): array
    {
        $c = strtoupper(trim($country));
        $c = $c === 'GR' ? 'EL' : $c;
        $v = strtoupper(preg_replace('/[\s.\-\/]/', '', $vat) ?? '');
        foreach ([$c, $c === 'EL' ? 'GR' : $c] as $prefix) {
            if ($prefix !== '' && str_starts_with($v, $prefix)) {
                $v = substr($v, strlen($prefix));
                break;
            }
        }

        return ['country' => $c, 'vat' => $v];
    }

    /** @return array{status: string, name: ?string, address: ?string, request_identifier: ?string, message: ?string, checked_at: string, id: ?int} */
    public function check(Company $company, ?User $actor, string $country, string $vat): array
    {
        ['country' => $c, 'vat' => $v] = self::normalize($country, $vat);
        if (! in_array($c, self::COUNTRIES, true)) {
            RuleViolation::fail(['vat' => 'Paese non verificabile con VIES: serve uno Stato membro dell’UE.']);
        }
        if ($v === '' || ! preg_match('/^[A-Z0-9]{2,14}$/', $v)) {
            RuleViolation::fail(['vat' => 'Indica la partita IVA / il numero di identificazione IVA.']);
        }
        $r = ['status' => 'non_disponibile', 'name' => null, 'address' => null, 'request_identifier' => null, 'message' => null];
        if ($c === 'IT' && ! PartitaIva::isValid($v)) {
            $r = ['status' => 'non_valida', 'name' => null, 'address' => null, 'request_identifier' => null, 'message' => 'Partita IVA italiana non valida (cifra di controllo errata).'];
        } else {
            $r = [...$r, ...$this->ask($company, $c, $v)];
        }
        $row = ViesCheck::create([...$r, 'company_id' => $company->id, 'country' => $c, 'vat' => $v, 'checked_by' => $actor?->id, 'checked_at' => now()]);
        Audit::record('vies.checked', $actor, $company, $row, data: ['vat' => $c.$v, 'status' => $r['status'], 'request' => $r['request_identifier']]);

        return [...$r, 'checked_at' => $row->checked_at->toIso8601String(), 'id' => $row->id, 'country' => $c, 'vat' => $v];
    }

    /** Ultima verifica di questo numero diversa da «non raggiungibile» negli ultimi $days giorni (per l'avviso nel modulo). */
    public function lastResult(Company $company, string $country, string $vat, int $days = 30): ?ViesCheck
    {
        ['country' => $c, 'vat' => $v] = self::normalize($country, $vat);

        return ViesCheck::where('company_id', $company->id)->where('country', $c)->where('vat', $v)->where('status', '!=', 'non_disponibile')
            ->where('checked_at', '>=', now()->subDays($days))->latest('id')->first();
    }

    /** @return array<string, ?string> */
    private function ask(Company $company, string $country, string $vat): array
    {
        $body = ['countryCode' => $country, 'vatNumber' => $vat];
        if ($company->partita_iva && PartitaIva::isValid((string) $company->partita_iva)) {
            $body += ['requesterMemberStateCode' => 'IT', 'requesterNumber' => (string) $company->partita_iva];
        }
        try {
            $res = Http::timeout((int) config('semplicon.vies.timeout', 8))->acceptJson()->asJson()->post((string) config('semplicon.vies.url'), $body);
        } catch (Throwable) {
            return ['status' => 'non_disponibile', 'message' => 'Servizio VIES non raggiungibile. Riprova più tardi.'];
        }
        $j = $res->json();
        if (! $res->successful() || ! is_array($j)) {
            return ['status' => 'non_disponibile', 'message' => 'VIES ha risposto con un errore. Riprova più tardi.'];
        }
        $err = strtoupper((string) ($j['userError'] ?? ''));
        if (in_array($err, ['MS_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'TIMEOUT', 'SERVICE_UNAVAILABLE', 'GLOBAL_MAX_CONCURRENT_REQ', 'MS_MAX_CONCURRENT_REQ_TIME'], true)) {
            return ['status' => 'non_disponibile', 'message' => 'Il servizio dello Stato membro non è disponibile. Riprova più tardi.'];
        }
        $clean = fn ($x) => ($x === null || trim((string) $x) === '' || trim((string) $x) === '---') ? null : trim((string) $x);
        $valid = (bool) ($j['valid'] ?? false);

        return ['status' => $valid ? 'valida' : 'non_valida', 'name' => $clean($j['name'] ?? null), 'address' => $clean(preg_replace('/\s*\n\s*/', ', ', (string) ($j['address'] ?? ''))),
            'request_identifier' => $clean($j['requestIdentifier'] ?? null), 'message' => $valid ? null : 'Numero non registrato in VIES.'];
    }
}
