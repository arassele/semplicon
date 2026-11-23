<?php

namespace App\Domain;

use App\Domain\Issuing\InvoiceIssuer;
use App\Enums\InvoiceDirection;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Support\Validation\CodiceFiscale;
use App\Support\Validation\Iban;
use App\Support\Validation\PartitaIva;

/**
 * Anagrafica clienti/fornitori. La verifica corrisponde all'emissione della fattura (cifra di controllo della partita IVA, codice fiscale, sigla del paese),
 * ma è più indulgente sui campi indirizzo: mancano solo all'emissione a un cliente (lì il modulo verifica rigorosamente).
 */
final class PartnerService
{
    /** @param  array<string, mixed>  $d */
    public function save(Company $company, User $actor, ?Partner $partner, array $d): Partner
    {
        $e = [];
        $country = strtoupper(trim((string) ($d['country'] ?? 'IT'))) ?: 'IT';
        $person = ($d['kind'] ?? 'azienda') === 'persona';
        $name = trim((string) ($d['name'] ?? ''));
        $first = trim((string) ($d['first_name'] ?? ''));
        $last = trim((string) ($d['last_name'] ?? ''));
        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            $e['country'] = 'Indica il paese con due lettere (es. IT, DE).';
        }
        if ($person ? ($first === '' || $last === '') : $name === '') {
            $e[$person ? 'first_name' : 'name'] = $person ? 'Indica nome e cognome.' : 'Indica la denominazione.';
        }
        if (! ($d['is_customer'] ?? false) && ! ($d['is_supplier'] ?? false)) {
            $e['is_customer'] = 'Scegli se è un cliente, un fornitore o entrambi.';
        }
        $vatRaw = strtoupper(preg_replace('/\s+/', '', (string) ($d['vat'] ?? '')) ?? '');
        $cf = strtoupper(preg_replace('/\s+/', '', (string) ($d['cf'] ?? '')) ?? '');
        $vat = null;
        if ($vatRaw !== '') {
            $code = str_starts_with($vatRaw, $country) ? substr($vatRaw, 2) : $vatRaw;
            if ($country === 'IT' && ! PartitaIva::isValid($code)) {
                $e['vat'] = 'La partita IVA non è valida (cifra di controllo errata).';
            } elseif ($country !== 'IT' && ! preg_match('/^[A-Z0-9]{2,28}$/', $code)) {
                $e['vat'] = 'Identificativo fiscale estero non valido.';
            }
            $vat = $country.$code;
        }
        if ($cf !== '' && $country === 'IT' && ! CodiceFiscale::isValid($cf) && ! preg_match('/^\d{11}$/', $cf)) {
            $e['cf'] = 'Il codice fiscale non è valido.';
        }
        if (! $vat && $cf === '' && ! isset($e['vat'])) {
            $e['vat'] = 'Indica la partita IVA o il codice fiscale.';
        }
        $province = strtoupper(trim((string) ($d['province'] ?? '')));
        if ($province !== '' && ! preg_match('/^[A-Z]{2}$/', $province)) {
            $e['province'] = 'Indica la sigla della provincia (es. MI).';
        }
        $sdi = strtoupper(trim((string) ($d['sdi_code'] ?? '')));
        if ($sdi !== '' && ! preg_match('/^[A-Z0-9]{6,7}$/', $sdi)) {
            $e['sdi_code'] = 'Il codice destinatario ha 7 caratteri (6 per la PA).';
        }
        $pec = trim((string) ($d['pec'] ?? ''));
        $email = trim((string) ($d['email'] ?? ''));
        foreach (['pec' => $pec, 'email' => $email] as $k => $v) {
            if ($v !== '' && ! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $e[$k] = 'Indirizzo e-mail non valido.';
            }
        }
        $iban = trim((string) ($d['iban'] ?? '')) !== '' ? Iban::normalize((string) $d['iban']) : null;
        if ($iban !== null && ! Iban::isValid($iban)) {
            $e['iban'] = 'IBAN non valido.';
        }
        $mode = (string) ($d['payment_mode'] ?? 'MP05');
        if (! isset(InvoiceIssuer::PAYMENT_MODES[$mode])) {
            $e['payment_mode'] = 'Modalità di pagamento non valida.';
        }
        $dup = Partner::where('company_id', $company->id)->when($partner, fn ($q) => $q->whereKeyNot($partner->id))
            ->where(fn ($q) => $q->when($vat, fn ($w) => $w->where('vat', $vat))->when($cf !== '', fn ($w) => $w->orWhere('cf', $cf)))->when(! $vat && $cf === '', fn ($q) => $q->whereRaw('1 = 0'))->first();
        if ($dup) {
            $e['vat'] = 'Esiste già un’anagrafica con questa partita IVA o questo codice fiscale: '.$dup->displayName().'.';
        }
        if ($e) {
            RuleViolation::fail($e);
        }
        $v = ['is_customer' => (bool) ($d['is_customer'] ?? false), 'is_supplier' => (bool) ($d['is_supplier'] ?? false), 'kind' => $person ? 'persona' : 'azienda', 'name' => $person ? null : $name, 'first_name' => $person ? $first : null,
            'last_name' => $person ? $last : null, 'vat' => $vat, 'cf' => $cf ?: null, 'country' => $country, 'address' => trim((string) ($d['address'] ?? '')) ?: null, 'zip' => trim((string) ($d['zip'] ?? '')) ?: null,
            'city' => trim((string) ($d['city'] ?? '')) ?: null, 'province' => $province ?: null, 'sdi_code' => $sdi ?: null, 'pec' => $pec ?: null, 'is_pa' => (bool) ($d['is_pa'] ?? false), 'email' => $email ?: null,
            'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'iban' => $iban, 'payment_mode' => $mode, 'payment_days' => max(0, min(365, (int) ($d['payment_days'] ?? 30))), 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
            'active' => (bool) ($d['active'] ?? true)];
        $partner = $partner ? tap($partner)->update($v) : $company->partners()->create($v);
        Audit::record($partner->wasRecentlyCreated ? 'partner.created' : 'partner.updated', $actor, $company, $partner, data: ['name' => $partner->displayName(), 'vat' => $vat]);

        return $partner;
    }

    public function delete(Company $company, User $actor, Partner $partner): void
    {
        Audit::record('partner.deleted', $actor, $company, $partner, data: ['name' => $partner->displayName(), 'vat' => $partner->vat]);
        $partner->delete();
    }

    /** Crea Anagrafiche dalle controparti delle fatture (vendita → cliente, acquisto → fornitore); quelle esistenti vengono integrate, nulla in doppio. @return array{created: int, updated: int} */
    public function importFromInvoices(Company $company, User $actor): array
    {
        $created = $updated = 0;
        foreach ($company->invoices()->whereIn('direction', ['vendita', 'acquisto'])->orderBy('id')->get() as $i) {
            $sale = $i->direction === InvoiceDirection::Vendita;
            $name = trim((string) ($sale ? $i->customer_name : $i->supplier_name));
            $vat = trim((string) ($sale ? $i->customer_vat : $i->supplier_vat)) ?: null;
            $cf = trim((string) ($sale ? $i->customer_cf : $i->supplier_cf)) ?: null;
            if ($name === '' || (! $vat && ! $cf)) {
                continue;
            }
            $p = Partner::where('company_id', $company->id)->where(fn ($q) => $q->when($vat, fn ($w) => $w->where('vat', $vat))->when($cf, fn ($w) => $w->orWhere('cf', $cf)))->first();
            $flag = $sale ? 'is_customer' : 'is_supplier';
            if ($p) {
                if (! $p->{$flag}) {
                    $p->update([$flag => true]);
                    $updated++;
                }

                continue;
            }
            $a = (array) ($sale ? $i->customer_address : $i->supplier_address);
            $country = $vat ? substr($vat, 0, 2) : ((string) ($sale ? $i->customer_country : $i->supplier_country) ?: 'IT');
            $company->partners()->create([$flag => true, 'kind' => 'azienda', 'name' => mb_substr($name, 0, 120), 'vat' => $vat, 'cf' => $cf, 'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : 'IT',
                'address' => mb_substr((string) ($a['indirizzo'] ?? ''), 0, 80) ?: null, 'zip' => mb_substr((string) ($a['cap'] ?? ''), 0, 10) ?: null, 'city' => mb_substr((string) ($a['comune'] ?? ''), 0, 60) ?: null,
                'province' => preg_match('/^[A-Za-z]{2}$/', (string) ($a['provincia'] ?? '')) ? strtoupper($a['provincia']) : null]);
            $created++;
        }
        Audit::record('partner.imported', $actor, $company, data: ['created' => $created, 'updated' => $updated]);

        return compact('created', 'updated');
    }
}
