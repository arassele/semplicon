<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * I02: documenti (file originali), esecuzioni di importazione, fatture da FatturaPA.
 * Importi in denaro come decimal, elaborati in PHP come testo (mai float).
 */
return new class extends Migration
{
    public function up(): void
    {
        // File originali, invariati nell'archivio privato; univoci per azienda tramite SHA-256.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->string('kind', 8);                 // xml | p7m | zip
            $table->string('storage_path', 255);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'sha256']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('status', 16);              // App\Enums\ImportStatus
            $table->unsignedInteger('files')->default(0);
            $table->unsignedInteger('invoices')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('to_review')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('import_items')->cascadeOnDelete(); // Voce da un file ZIP
            $table->string('filename', 255);
            $table->string('staging_path', 255)->nullable();
            $table->string('status', 16);              // App\Enums\ImportItemStatus
            $table->json('messages')->nullable();
            $table->unsignedInteger('invoices')->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained();
            $table->foreignId('import_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('body_index');           // Posizione del FatturaElettronicaBody nel file (base 1)
            $table->string('format', 8);                          // FPR12 | FPA12
            $table->string('direction', 8)->nullable();           // acquisto | vendita; null = non univoco
            $table->string('doc_type', 4);                        // TD01 …
            $table->string('number', 20);
            $table->date('doc_date');
            $table->char('currency', 3);
            $table->decimal('total_amount', 15, 2)->nullable();   // ImportoTotaleDocumento, se indicato
            $table->decimal('taxable_total', 15, 2);              // Somma ImponibileImporto
            $table->decimal('tax_total', 15, 2);                  // Somma Imposta
            $table->string('supplier_name', 200);
            $table->string('supplier_vat', 30)->nullable();       // IdPaese + IdCodice
            $table->string('supplier_cf', 16)->nullable();
            $table->string('customer_name', 200);
            $table->string('customer_vat', 30)->nullable();
            $table->string('customer_cf', 16)->nullable();
            $table->char('identity_key', 64);                     // Direzione, controparte, tipo, numero, data
            $table->char('content_hash', 64);                     // C14N del body
            $table->string('status', 16);                         // App\Enums\InvoiceStatus
            $table->json('issues')->nullable();
            $table->foreignId('conflicts_with')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();
            // Stessa identità sostanziale con lo stesso contenuto una sola volta, anche con importazioni parallele.
            $table->unique(['company_id', 'identity_key', 'content_hash']);
            $table->index(['company_id', 'direction', 'doc_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('invoice_vat_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate', 6, 2);
            $table->string('natura', 5)->nullable();
            $table->decimal('taxable', 15, 2);
            $table->decimal('tax', 15, 2);
            $table->char('esigibilita', 1)->nullable();
            $table->string('normative_reference', 100)->nullable();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->text('description');
            $table->decimal('quantity', 21, 8)->nullable();
            $table->string('unit', 10)->nullable();
            $table->decimal('unit_price', 21, 8);
            $table->decimal('total', 21, 8);
            $table->decimal('rate', 6, 2);
            $table->string('natura', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoice_vat_summaries');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('import_items');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('documents');
    }
};
