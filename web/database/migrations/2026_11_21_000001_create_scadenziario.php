<?php

use App\Support\Decimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scadenziario: rate (partite aperte), movimenti di denaro, attribuzioni e attribuzione fiscale per anno d'imposta.
 * Rata, movimento di denaro e attribuzione sono record separati; una rata può avere più pagamenti,
 * un movimento può compensare più rate dello stesso partner commerciale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('ritenuta', 15, 2)->default(0);            // DatiRitenuta/ImportoRitenuta
            $table->boolean('payment_check')->default(false);          // «Stato pagamento da verificare»
            $table->boolean('amount_check')->default(false);           // «Importo da verificare»
            $table->string('payment_condition', 4)->nullable();        // CondizioniPagamento (TP01…)
        });

        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->date('due_date')->nullable();                      // manca → «Scadenza da definire»
            $table->decimal('amount', 15, 2);                          // effettivamente da pagare (positivo)
            $table->string('payment_mode', 4)->nullable();             // ModalitaPagamento (MP05…)
            $table->string('source', 10);                              // xml | manuale | migrazione | calcolata
            $table->boolean('edited')->default(false);                 // corretto manualmente
            $table->timestamps();
            $table->unique(['invoice_id', 'seq']);
            $table->index(['company_id', 'due_date']);
        });

        Schema::create('money_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                                // incasso | pagamento
            $table->string('party_key', 200);
            $table->string('party_name', 200);
            $table->date('movement_date');                             // data effettiva del pagamento/accredito
            $table->decimal('amount', 15, 2);
            $table->string('method', 40)->nullable();
            $table->string('bank_ref', 100)->nullable();
            $table->text('notes')->nullable();
            $table->date('payer_date')->nullable();                    // Professionista: data di pagamento del sostituto d'imposta
            $table->boolean('payer_year_rule')->default(false);        // attribuzione all'anno del sostituto confermata
            $table->string('status', 10)->default('valida');           // valida | stornata
            $table->text('reversal_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'request_id']);
            $table->index(['company_id', 'movement_date']);
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('money_movements')->cascadeOnDelete();
            $table->foreignId('credit_installment_id')->nullable()->constrained('installments')->cascadeOnDelete(); // Compensazione con nota di credito
            $table->string('type', 12);                                // pagamento | nota_credito
            $table->date('allocation_date');
            $table->decimal('amount', 15, 2);
            $table->string('status', 10)->default('valida');           // valida | stornata
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['company_id', 'allocation_date']);
        });

        Schema::create('fiscal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('allocation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('vat_registrations')->nullOnDelete();
            $table->unsignedSmallInteger('component');                 // Indice della riga imputata
            $table->date('event_date');
            $table->unsignedSmallInteger('year');
            $table->string('basis', 14);                               // cassa | registrazione | da_verificare
            $table->string('nature', 8);                               // ricavo | costo
            $table->decimal('amount', 15, 2);                          // Quota della componente (con segno)
            $table->decimal('fiscal_amount', 15, 2);                   // dopo il limite di deducibilità
            $table->text('reason');
            $table->timestamps();
            $table->index(['company_id', 'year']);
        });

        // Fatture esistenti: una rata per l'importo da pagare, senza scadenza, stato di pagamento da verificare.
        $now = now();
        foreach (DB::table('invoices')->get() as $i) {
            // Importi in centesimi (nessun float): totale del documento, altrimenti imponibile + imposta.
            $total = $i->total_amount !== null ? Decimal::toMinor((string) $i->total_amount) : Decimal::toMinor((string) $i->taxable_total) + Decimal::toMinor((string) $i->tax_total);
            DB::table('installments')->insert(['company_id' => $i->company_id, 'invoice_id' => $i->id, 'seq' => 1, 'due_date' => null,
                'amount' => Decimal::fromMinor(abs($total)), 'payment_mode' => null, 'source' => 'migrazione', 'edited' => false, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('invoices')->where('id', $i->id)->update(['payment_check' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_entries');
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('money_movements');
        Schema::dropIfExists('installments');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['ritenuta', 'payment_check', 'amount_check', 'payment_condition']);
        });
    }
};
