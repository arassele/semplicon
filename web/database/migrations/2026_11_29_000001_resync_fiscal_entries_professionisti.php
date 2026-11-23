<?php

use App\Domain\Scadenziario\FiscalService;
use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Professionisti ai fini fiscali sempre per cassa, anche se forfettari (decisione n. 77): ricalcolare le attribuzioni esistenti. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('vat_profiles')->where('regime', 'professionisti')->where('forfettario_iva', true)->distinct()->pluck('company_id');
        foreach (Company::whereIn('id', $ids)->get() as $c) {
            app(FiscalService::class)->syncCompany($c);
        }
    }

    public function down(): void {}
};
