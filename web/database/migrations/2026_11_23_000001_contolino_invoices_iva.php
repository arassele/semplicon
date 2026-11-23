<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incarico di sviluppo «Fatture e IVA» (19.11.2026): archivio per documenti non registrati, data di ricezione, indirizzi,
 * valuta estera, rielaborazione di XML con versioni, integrazione IVA (reverse charge) ed esportazioni XML.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archived_from', 20)->nullable();           // Stato prima dell'archiviazione
            $table->date('receipt_date')->nullable();                  // Data di ricezione (acquisti)
            $table->json('supplier_address')->nullable();              // Sede: indirizzo, cap, comune, provincia, nazione
            $table->json('customer_address')->nullable();
            $table->string('supplier_country', 2)->nullable();
            $table->string('customer_country', 2)->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();       // Valuta estera: 1 EUR = x valuta
            $table->decimal('original_total', 15, 2)->nullable();      // Importo nella valuta originale
            $table->string('xb_kind', 16)->nullable();                 // servizi | beni_ue | beni_17c2 | beni_extra_ue
            $table->boolean('xb_relevant')->nullable();                // rilevante territorialmente in Italia
            $table->boolean('xb_customs')->default(false);             // Bolletta doganale presente
            $table->boolean('xb_sdi')->default(false);                 // già emessa/ricevuta tramite SdI
        });
        DB::table('invoices')->whereNull('source')->orWhere('source', '!=', 'manuale')->update(['xb_sdi' => true]);

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->decimal('original_total', 15, 2)->nullable();
            $table->string('legal_ref', 100)->nullable();
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('mode', 12)->default('salta');               // salta | rielabora
        });

        Schema::create('invoice_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('snapshot');
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 255);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['invoice_id', 'version']);
        });

        Schema::create('invoice_reprocessings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('import_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('body_index');
            $table->json('payload');                                   // dati rideterminati (body + parti)
            $table->json('diff')->nullable();
            $table->json('impacts')->nullable();
            $table->json('candidates')->nullable();                    // Corrispondenza da verificare
            $table->boolean('booking_relevant')->default(false);
            $table->string('status', 12);                              // in_attesa | applicata | scartata | bloccata | da_abbinare
            $table->text('message')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('invoice_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 4);                             // TD16 | TD17 | TD18 | TD19
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number');                         // Progressivo dell’autofattura/integrazione
            $table->date('doc_date');
            $table->json('groups');                                    // per gruppo IVA: base, aliquota, imposta, % detraibile …
            $table->string('reason', 255)->nullable();                 // Motivazione della proposta
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete(); // File TD16–TD19 importato associato
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'year', 'number']);
        });

        Schema::table('vat_registrations', function (Blueprint $table) {
            $table->foreignId('integration_id')->nullable()->constrained('invoice_integrations')->nullOnDelete();
        });
        Schema::table('vat_registration_lines', function (Blueprint $table) {
            $table->boolean('integrated')->default(false);             // IVA integrata: anche nel Registro vendite
        });

        Schema::create('xml_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained('invoice_integrations')->nullOnDelete();
            $table->string('doc_type', 4);
            $table->unsignedSmallInteger('version');
            $table->string('filename', 60);
            $table->longText('content');
            $table->string('sha256', 64);
            $table->timestamp('generated_at');
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['invoice_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xml_exports');
        Schema::table('vat_registration_lines', fn (Blueprint $t) => $t->dropColumn('integrated'));
        Schema::table('vat_registrations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('integration_id');
        });
        Schema::dropIfExists('invoice_integrations');
        Schema::dropIfExists('invoice_reprocessings');
        Schema::dropIfExists('invoice_versions');
        Schema::table('import_batches', fn (Blueprint $t) => $t->dropColumn('mode'));
        Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropColumn(['original_total', 'legal_ref']));
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('archived_by');
            $t->dropColumn(['archived_at', 'archived_from', 'receipt_date', 'supplier_address', 'customer_address', 'supplier_country', 'customer_country',
                'exchange_rate', 'original_total', 'xb_kind', 'xb_relevant', 'xb_customs', 'xb_sdi']);
        });
    }
};
