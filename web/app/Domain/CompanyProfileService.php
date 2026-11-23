<?php

namespace App\Domain;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\User;
use App\Support\Validation\Iban;
use Illuminate\Support\Facades\DB;

/** Anagrafica di un'azienda («Dati azienda»): dati di intestazione, recapiti, attività, conti bancari. */
final class CompanyProfileService
{
    public function update(Company $company, User $actor, array $data): Company
    {
        return DB::transaction(function () use ($company, $actor, $data) {
            $type = CompanyType::from($data['tipologia']);

            // Per le persone l'intestazione risulta da cognome e nome, se non è indicata una denominazione.
            $name = trim((string) ($data['name'] ?? ''));
            if ($type->isPerson() && $name === '') {
                $name = trim(($data['cognome'] ?? '').' '.($data['nome'] ?? ''));
            }

            $company->fill([...array_intersect_key($data, array_flip($company->getFillable())), 'name' => $name]);
            $changed = array_keys($company->getDirty());
            $company->save();

            $company->contacts()->delete();
            foreach ($data['contacts'] ?? [] as $c) {
                $company->contacts()->create($c);
            }
            $company->activities()->delete();
            foreach ($data['activities'] ?? [] as $a) {
                $company->activities()->create($a);
            }
            $company->bankAccounts()->delete();
            foreach ($data['bank_accounts'] ?? [] as $b) {
                $company->bankAccounts()->create([...$b, 'iban' => Iban::normalize($b['iban'])]);
            }

            Audit::record('company.updated', $actor, $company, $company, data: [
                'fields' => $changed,
                'contacts' => count($data['contacts'] ?? []),
                'activities' => count($data['activities'] ?? []),
                'bank_accounts' => count($data['bank_accounts'] ?? []),
            ]);

            return $company;
        });
    }
}
