<?php

namespace App\Domain;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CompanyService
{
    /** Un cliente crea da sé la propria azienda e la gestisce poi integralmente. */
    public function create(User $actor, array $data): Company
    {
        return DB::transaction(function () use ($actor, $data) {
            $company = Company::create([...$data, 'created_by' => $actor->id]);
            Membership::create(['company_id' => $company->id, 'user_id' => $actor->id]);
            app(AccountService::class)->ensureDefaults($company);
            Audit::record('company.created', $actor, $company, $company, data: ['name' => $company->name]);

            return $company;
        });
    }
}
