<?php

use App\Domain\AccountService;
use App\Domain\Cespiti\DepreciationTableImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo Cespiti (decisione n. 79): tabelle del D.M. 31.12.1988 (immutabili), categorie, cespiti, aliquote da anno, saldi di apertura,
 * ammortamenti per anno ed esecuzione, movimenti, documenti, esecuzioni di importazione, Prima nota cespiti (giornale) e anni chiusi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $money = fn (Blueprint $t, string $c, bool $null = false) => $null ? $t->decimal($c, 15, 2)->nullable() : $t->decimal($c, 15, 2)->default(0);

        Schema::create('depreciation_tables', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('title', 255);
            $t->string('source', 255);
            $t->string('version', 40);
            $t->date('effective_from')->nullable();
            $t->string('source_sha256', 64)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('depreciation_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('depreciation_table_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('gruppo');
            $t->string('gruppo_desc', 255);
            $t->string('specie', 30)->nullable();
            $t->text('specie_desc')->nullable();
            $t->text('description');
            $t->decimal('coefficient', 5, 2);
            $t->string('verify_note', 255)->nullable();
            $t->unsignedInteger('position');
            $t->index(['depreciation_table_id', 'gruppo']);
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('number');
            $t->date('entry_date');
            $t->string('description', 255);
            $t->string('source_key', 60);                       // es. run:12, mov:33, run:12:storno – impedisce le doppie registrazioni
            $t->string('status', 14);                           // contabilizzato | stornato
            $t->foreignId('reverses_id')->nullable()->constrained('journal_entries');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'year', 'number']);
            $t->unique(['company_id', 'source_key']);
        });
        Schema::create('journal_lines', function (Blueprint $t) use ($money) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained();
            $money($t, 'dare');
            $money($t, 'avere');
            $t->string('description', 255)->nullable();
        });

        Schema::create('asset_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 200);
            $t->string('kind', 20);                             // materiale | immateriale | non_ammortizzabile
            $t->foreignId('depreciation_rate_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('civil_rate', 5, 2)->nullable();
            $t->decimal('fiscal_rate', 5, 2)->nullable();       // aliquota fiscale applicata
            $t->boolean('first_year_half')->default(true);
            foreach (['asset', 'fund', 'expense', 'gain', 'loss', 'writedown'] as $a) {
                $t->foreignId("account_{$a}_id")->nullable()->constrained('accounts')->nullOnDelete();
            }
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('asset_category_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_category_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('from_year');
            $t->decimal('civil_rate', 5, 2);
            $t->decimal('fiscal_rate', 5, 2);
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['asset_category_id', 'from_year']);
        });

        Schema::create('assets', function (Blueprint $t) use ($money) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('number');
            $t->string('code', 30);
            $t->string('description', 200);
            $t->text('description_long')->nullable();
            $t->foreignId('asset_category_id')->constrained();
            $t->string('subcategory', 120)->nullable();
            $t->string('kind', 20);
            $t->string('status', 20)->default('attivo');       // attivo | ammortizzato | venduto | eliminato | sospeso
            foreach (['location', 'cost_center', 'serial_number', 'matricola', 'supplier_name'] as $c) {
                $t->string($c, 120)->nullable();
            }
            $t->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $t->text('notes')->nullable();
            $t->date('purchase_date');
            $t->date('invoice_date')->nullable();
            $t->string('invoice_number', 40)->nullable();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->date('in_service_date')->nullable();
            $t->date('depreciation_start')->nullable();
            $money($t, 'original_cost');
            $money($t, 'accessory_costs');
            $money($t, 'nondeductible_vat');
            $money($t, 'grants');
            $t->string('grant_treatment', 16)->default('riduzione'); // riduzione (costo netto) | risconto (ricavo pluriennale, non detratto)
            $money($t, 'residual_value');
            $money($t, 'fiscal_cost', true);                    // costo fiscalmente riconosciuto, null = come civilistico
            $t->string('method', 12)->default('ordinario');    // ordinario | ridotto | pieno | manuale
            $t->decimal('reduced_pct', 6, 2)->nullable();
            $t->boolean('first_year_half')->nullable();
            $t->boolean('excluded')->default(false);
            $t->text('excluded_reason')->nullable();
            $t->decimal('extra_deduction_pct', 6, 2)->nullable(); // Super/iperammortamento: solo su indicazione
            $t->string('extra_deduction_note', 255)->nullable();
            $money($t, 'fiscal_cost_cap', true);                // deducibilità limitata (z. B. Autovetture)
            $t->decimal('fiscal_deductible_pct', 6, 2)->nullable();
            $t->boolean('low_value')->default(false);           // art. 102 c. 5 TUIR: deduzione nell'anno
            foreach (['asset', 'fund', 'expense', 'gain', 'loss', 'writedown'] as $a) {
                $t->foreignId("account_{$a}_id")->nullable()->constrained('accounts')->nullOnDelete();
            }
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->unique(['company_id', 'number']);
        });
        Schema::create('asset_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('from_year');
            $t->decimal('civil_rate', 5, 2);
            $t->decimal('fiscal_rate', 5, 2);
            $t->decimal('manual_civil', 15, 2)->nullable();    // Metodo manuale: quota per esattamente questo anno
            $t->decimal('manual_fiscal', 15, 2)->nullable();
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['asset_id', 'from_year']);
        });
        Schema::create('asset_opening_balances', function (Blueprint $t) use ($money) {
            $t->id();
            $t->foreignId('asset_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('mode', 8);                              // saldi | storico
            $t->unsignedSmallInteger('last_year');
            $money($t, 'fund_civil');
            $money($t, 'fund_fiscal');
            $money($t, 'increments');
            $money($t, 'decrements');
            $money($t, 'revaluations');
            $t->unsignedBigInteger('import_job_id')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('asset_depreciation_runs', function (Blueprint $t) use ($money) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->string('status', 14);                           // calcolato | contabilizzato | stornato
            $money($t, 'total_civil');
            $money($t, 'total_fiscal');
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $t->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries');
            $t->text('reason')->nullable();
            foreach (['calculated', 'posted', 'reversed'] as $s) {
                $t->foreignId("{$s}_by")->nullable()->constrained('users');
                $t->timestamp("{$s}_at")->nullable();
            }
            $t->timestamps();
            $t->index(['company_id', 'year']);
        });
        Schema::create('asset_depreciation', function (Blueprint $t) use ($money) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->foreignId('asset_depreciation_run_id')->nullable()->constrained()->nullOnDelete();
            $t->string('method', 12);
            $t->decimal('civil_rate', 6, 2)->nullable();
            $t->decimal('fiscal_rate', 6, 2)->nullable();
            foreach (['base_civil', 'base_fiscal', 'fund_civil_start', 'civil_amount', 'fund_civil_end', 'fund_fiscal_start', 'fiscal_limit', 'fiscal_amount', 'fund_fiscal_end', 'nondeductible', 'extra_deduction'] as $c) {
                $money($t, $c);
            }
            $t->unsignedSmallInteger('days')->nullable();      // Frazione in caso di vendita/dismissione nell'anno
            $t->string('status', 14);                           // calcolato | contabilizzato | stornato | storico
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['company_id', 'year']);
            $t->index(['asset_id', 'year']);
        });
        Schema::create('asset_movements', function (Blueprint $t) use ($money) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->string('type', 24);
            $t->date('movement_date');
            $t->unsignedSmallInteger('year');
            foreach (['amount', 'fiscal_amount', 'price', 'vat', 'sale_costs', 'book_value', 'fiscal_value', 'gain_civil', 'gain_fiscal', 'fund_civil', 'fund_fiscal', 'cost_civil', 'cost_fiscal'] as $c) {
                $money($t, $c, true);
            }
            $t->decimal('share_pct', 6, 2)->nullable();
            $t->string('plan_mode', 16)->nullable();            // residuo | costo (incremento: base della quota futura)
            $t->boolean('fiscal_recognized')->default(false);   // Rivalutazione fiscalmente riconosciuta (solo con base)
            $t->json('data')->nullable();
            $t->text('causale');
            $t->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $t->string('status', 12)->default('registrato'); // registrato | stornato
            $t->foreignId('reverses_id')->nullable()->constrained('asset_movements');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['asset_id', 'movement_date']);
        });
        Schema::create('asset_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->constrained();
            $t->string('label', 120)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('asset_import_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->string('filename', 255);
            $t->string('mode', 8);
            $t->string('status', 12);                           // anteprima | importato | annullato
            $t->json('mapping')->nullable();
            $t->json('report')->nullable();
            $t->unsignedInteger('rows')->default(0);
            $t->unsignedInteger('imported')->default(0);
            $t->timestamps();
        });
        Schema::create('fiscal_year_closures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->timestamp('closed_at');
            $t->foreignId('closed_by')->constrained('users');
            $t->timestamp('reopened_at')->nullable();
            $t->foreignId('reopened_by')->nullable()->constrained('users');
            $t->text('reopen_reason')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'year']);
        });
        Schema::table('companies', function (Blueprint $t) {
            $t->json('asset_settings')->nullable();
        });

        Schema::table('accounts', fn (Blueprint $t) => $t->string('type', 16)->change()); // costo | ricavo | patrimoniale
        // Conti patrimoniali per le aziende esistenti (senza sovrascrivere i codici esistenti)
        $rows = AccountService::model(AccountService::PATRIMONIALE);
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $cid) {
            $have = DB::table('accounts')->where('company_id', $cid)->pluck('code')->flip();
            foreach ($rows as $r) {
                if (! isset($have[$r['code']])) {
                    DB::table('accounts')->insert([...$r, 'company_id' => $cid, 'source' => 'modello', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        // Tabella D.M. 31.12.1988 dalla fonte fornita dal committente (database/data)
        DepreciationTableImporter::importBundled();
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('asset_settings'));
        foreach (['fiscal_year_closures', 'asset_import_jobs', 'asset_documents', 'asset_movements', 'asset_depreciation', 'asset_depreciation_runs', 'asset_opening_balances',
            'asset_rates', 'assets', 'asset_category_rates', 'asset_categories', 'journal_lines', 'journal_entries', 'depreciation_rates', 'depreciation_tables'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        DB::table('accounts')->where('type', 'patrimoniale')->delete();
    }
};
