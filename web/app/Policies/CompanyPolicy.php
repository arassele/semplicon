<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/** I clienti gestiscono integralmente le proprie aziende; l'amministratore ha accesso a tutte. */
class CompanyPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->canAccess($company);
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->canAccess($company);
    }
}
