<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fatture manuali (senza importazione, allegato facoltativo), allegato a ogni registrazione,
 * movimenti fuori campo IVA (costi e ricavi al di fuori dei Registri IVA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->change();
            $table->string('source', 8)->default('import');     // import | manuale
            $table->text('description')->nullable();            // solo registrazione manuale
        });

        Schema::table('vat_registrations', function (Blueprint $table) {
            // Documento della registrazione (file originale dell'importazione o allegato della fattura manuale).
            $table->foreignId('document_id')->nullable()->constrained();
        });
        // Registrazioni esistenti: recepire il documento della fattura.
        foreach (DB::table('vat_registrations')->join('invoices', 'invoices.id', '=', 'vat_registrations.invoice_id')
            ->select('vat_registrations.id', 'invoices.document_id')->get() as $r) {
            DB::table('vat_registrations')->where('id', $r->id)->update(['document_id' => $r->document_id]);
        }

        Schema::create('non_vat_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);                           // costo | ricavo
            $table->date('entry_date');
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('protocol');            // numero progressivo per azienda e anno
            $table->foreignId('account_id')->constrained();
            $table->string('description', 255);
            $table->string('counterparty', 200)->nullable();
            $table->string('reference', 60)->nullable();         // es. numero della ricevuta
            $table->decimal('amount', 15, 2);                    // sempre positivo
            $table->smallInteger('sign')->default(1);            // Storno -1
            $table->foreignId('document_id')->nullable()->constrained();
            $table->string('status', 12);                        // registrato | stornato | storno
            $table->foreignId('reverses_id')->nullable()->constrained('non_vat_entries');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'year', 'protocol']);
            $table->index(['company_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_vat_entries');
        Schema::table('vat_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['source', 'description']);
        });
    }
};
