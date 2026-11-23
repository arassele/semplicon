<?php

use App\Support\InvoiceIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Ticket 08: risultato per corpo fattura nell'esecuzione di importazione; identità sostanziale (emittente, tipo, anno, numero) e hash del contenuto. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_items', function (Blueprint $table) {
            $table->json('results')->nullable();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->char('business_hash', 64)->nullable();
            $table->index(['company_id', 'identity_key']);
        });
        // Ricalcolare le fatture esistenti dai valori salvati.
        foreach (DB::table('invoices')->get() as $i) {
            $sum = DB::table('invoice_vat_summaries')->where('invoice_id', $i->id)->orderBy('id')->get()->map(fn ($s) => (array) $s)->all();
            $lines = DB::table('invoice_lines')->where('invoice_id', $i->id)->orderBy('id')->get()->map(fn ($l) => (array) $l)->all();
            $supplier = ['name' => $i->supplier_name, 'vat' => $i->supplier_vat, 'cf' => $i->supplier_cf];
            DB::table('invoices')->where('id', $i->id)->update([
                'identity_key' => InvoiceIdentity::key($supplier, $i->doc_type, (string) $i->doc_date, $i->number),
                'business_hash' => InvoiceIdentity::businessHash(['doc_type' => $i->doc_type, 'number' => $i->number, 'date' => substr((string) $i->doc_date, 0, 10), 'currency' => $i->currency,
                    'total_amount' => $i->total_amount, 'supplier' => $supplier, 'customer' => ['name' => $i->customer_name, 'vat' => $i->customer_vat, 'cf' => $i->customer_cf], 'summaries' => $sum, 'lines' => $lines]),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'identity_key']);
            $table->dropColumn('business_hash');
        });
        Schema::table('import_items', function (Blueprint $table) {
            $table->dropColumn('results');
        });
    }
};
