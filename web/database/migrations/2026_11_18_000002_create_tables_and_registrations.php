<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * I03: tabelle (Codici ATECO globali, Piano dei conti per azienda) e registrazioni IVA.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Codici ATECO 2025: tabella di riferimento per tutte le aziende, gestita dall'amministratore.
        Schema::create('ateco_codes', function (Blueprint $table) {
            $table->string('code', 8)->primary();
            $table->string('title_it', 255);
            $table->boolean('active')->default(true);
            $table->string('source', 12)->default('istat');   // istat | manuale
            $table->timestamps();
        });
        $now = now();
        $rows = [];
        $f = fopen(database_path('data/ateco_2025.csv'), 'r');
        fgetcsv($f, escape: '\\');
        while (($r = fgetcsv($f, escape: '\\')) !== false) {
            $rows[] = ['code' => $r[0], 'title_it' => $r[1], 'active' => true, 'source' => 'istat', 'created_at' => $now, 'updated_at' => $now];
        }
        fclose($f);
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('ateco_codes')->insert($chunk);
        }

        // Piano dei conti semplice per azienda: conti di costo e di ricavo per la registrazione.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 12);
            $table->string('name', 120);
            $table->string('type', 8);                          // costo | ricavo
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);      // Suggerimento per tipo
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->text('discard_reason')->nullable();
        });

        // Registrazione IVA: immutabile, correzione solo tramite storno.
        Schema::create('vat_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained();
            // Impostato solo per la registrazione valida di una fattura: l'indice univoco impedisce la doppia registrazione.
            $table->unsignedBigInteger('active_invoice_id')->nullable()->unique();
            $table->string('direction', 8);                     // acquisto | vendita
            $table->unsignedSmallInteger('year');               // Anno del periodo IVA
            $table->unsignedSmallInteger('protocol');           // Numero progressivo per azienda, registro e anno
            $table->string('periodicity', 16);                  // mensile | trimestrale (dal regime dell'anno)
            $table->unsignedTinyInteger('period');              // Mese 1–12 ovvero trimestre 1–4
            $table->date('registration_date');
            $table->date('receipt_date')->nullable();           // solo acquisti
            $table->smallInteger('sign');                       // +1, per nota di credito (TD04) -1
            $table->decimal('taxable_total', 15, 2);
            $table->decimal('tax_total', 15, 2);
            $table->decimal('deductible_total', 15, 2);         // solo acquisti, altrimenti 0
            $table->string('status', 12);                       // registrata | stornata | storno
            $table->foreignId('reverses_id')->nullable()->constrained('vat_registrations');
            $table->json('acknowledged')->nullable();           // avvisi di verifica confermati
            $table->text('notes')->nullable();
            $table->string('rule_version', 32);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'direction', 'year', 'protocol']);
            $table->index(['company_id', 'direction', 'year', 'period']);
        });

        Schema::create('vat_registration_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vat_registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->decimal('rate', 6, 2);
            $table->string('natura', 5)->nullable();
            $table->decimal('taxable', 15, 2);
            $table->decimal('tax', 15, 2);
            $table->decimal('deductible_pct', 5, 2);            // 0–100, solo acquisti
            $table->decimal('deductible_tax', 15, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_registration_lines');
        Schema::dropIfExists('vat_registrations');
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('discard_reason'));
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('ateco_codes');
    }
};
