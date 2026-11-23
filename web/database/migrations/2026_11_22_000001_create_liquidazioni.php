<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 11: liquidazioni IVA, deleghe F24, registro crediti IVA, pratiche di rimborso.
 * Liquidazione, credito, movimento di credito e delega sono record separati; ogni quota ha una sola destinazione attiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_profiles', function (Blueprint $table) {
            $table->string('quarterly_type', 10)->nullable();          // opzione | speciale (solo trimestrale)
        });
        // Profili trimestrali esistenti: per opzione (caso più frequente, visibile e correggibile nel profilo).
        DB::table('vat_profiles')->where('periodicity', 'trimestrale')->update(['quarterly_type' => 'opzione']);

        Schema::create('vat_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('period');
            $table->string('periodicity', 12);
            $table->string('quarterly_type', 10)->nullable();
            $table->unsignedSmallInteger('version');
            $table->string('status', 12);                              // confermata | sostituita (bozza = calcolata dal vivo)
            $table->foreignId('vat_profile_id')->constrained();
            $table->string('rule_version', 40);
            $table->json('data');                                      // Prospetto, dettagli, registrazioni, crediti
            $table->string('result', 8);                               // debito | credito | zero
            $table->decimal('amount_due', 15, 2)->default(0);          // da versare (Imposta + Interessi), 0 in caso di rinvio
            $table->decimal('credit', 15, 2)->default(0);              // risultato a credito
            $table->decimal('deferred', 15, 2)->default(0);            // piccolo debito rinviato
            $table->date('due_date')->nullable();
            $table->boolean('review_required')->default(false);       // «Liquidazione da riesaminare»
            $table->text('review_reason')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->foreignId('replaces_id')->nullable()->constrained('vat_settlements')->nullOnDelete();
            $table->foreignId('confirmed_by')->constrained('users');
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->unique(['company_id', 'year', 'period', 'version']);
            $table->unique(['company_id', 'request_id']);
        });

        Schema::create('vat_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('period');
            $table->string('kind', 12);                                // debito | detrazione | acconto
            $table->decimal('amount', 15, 2);                          // con segno (acconto positivo)
            $table->string('causale', 255);
            $table->string('reference', 120)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['company_id', 'year', 'period']);
        });

        Schema::create('vat_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);                                // periodico | trimestrale | annuale
            $table->string('origin', 14);                              // liquidazione | tr | dichiarazione
            $table->foreignId('settlement_id')->nullable()->constrained('vat_settlements')->nullOnDelete();
            $table->foreignId('source_credit_id')->nullable()->constrained('vat_credits')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('period')->nullable();         // Mese/trimestre di formazione, trimestre per il TR
            $table->decimal('amount', 15, 2);                          // maturato
            $table->string('description', 255);
            $table->date('filed_at')->nullable();                      // Dichiarazione/TR presentata il
            $table->boolean('visto')->default(false);                  // visto di conformità
            $table->decimal('visto_exempt_limit', 15, 2)->nullable(); // Esonero (es. ISA) fino a questo importo
            $table->string('status', 12)->default('attivo');           // attivo | sostituito
            $table->string('rule_version', 40);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['company_id', 'year']);
        });

        Schema::create('vat_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                                // tr | annuale
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->foreignId('credit_id')->nullable()->constrained('vat_credits')->nullOnDelete(); // Credito TR ovvero credito annuale
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->decimal('compensation_amount', 15, 2)->default(0);
            $table->string('presupposto', 40)->nullable();
            $table->text('documentation')->nullable();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12);                              // bozza | richiesto | erogato | respinto
            $table->date('deadline')->nullable();
            $table->date('filed_at')->nullable();
            $table->string('protocol', 60)->nullable();
            $table->date('outcome_at')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('f24_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('vat_settlements')->nullOnDelete();
            $table->date('due_date');
            $table->string('status', 22);                              // bozza | presentato | scartato | addebito_confermato
            $table->json('lines');                                     // Sezione Erario
            $table->decimal('total_debit', 15, 2);
            $table->decimal('total_credit', 15, 2);
            $table->decimal('balance', 15, 2);
            $table->string('rule_version', 40);
            $table->date('presented_at')->nullable();
            $table->string('protocol', 60)->nullable();
            $table->text('outcome')->nullable();
            $table->date('debit_confirmed_at')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'request_id']);
        });

        Schema::create('vat_credit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_id')->constrained('vat_credits')->cascadeOnDelete();
            $table->string('destination', 14);                         // riporto | compensazione | rimborso | tr
            $table->decimal('amount', 15, 2);
            $table->string('status', 12);                              // prenotata | utilizzata | sbloccata
            $table->unsignedSmallInteger('target_year')->nullable();   // Riporto in questa liquidazione
            $table->unsignedTinyInteger('target_period')->nullable();
            $table->foreignId('settlement_id')->nullable()->constrained('vat_settlements')->nullOnDelete(); // Liquidazione che utilizza il riporto
            $table->foreignId('f24_id')->nullable()->constrained('f24_drafts')->nullOnDelete();
            $table->foreignId('claim_id')->nullable()->constrained('vat_claims')->nullOnDelete();
            $table->foreignId('new_credit_id')->nullable()->constrained('vat_credits')->nullOnDelete(); // TR: credito formatosi
            $table->text('reason')->nullable();                        // Sblocco / adeguamento
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['company_id', 'target_year', 'target_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_credit_movements');
        Schema::dropIfExists('f24_drafts');
        Schema::dropIfExists('vat_claims');
        Schema::dropIfExists('vat_credits');
        Schema::dropIfExists('vat_adjustments');
        Schema::dropIfExists('vat_settlements');
        Schema::table('vat_profiles', fn (Blueprint $t) => $t->dropColumn('quarterly_type'));
    }
};
