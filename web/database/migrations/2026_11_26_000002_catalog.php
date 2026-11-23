<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catalogo per azienda: articoli/prestazioni, blocchi di testo (causale, testi di riga) e unità proprie (elenco requisiti 2.5–2.7). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->nullable();
            $table->string('description', 1000);
            $table->string('unit', 10)->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('rate', 5, 2)->default(22);
            $table->string('natura', 5)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        Schema::create('catalog_snippets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->string('body', 1000);
            $table->string('kind', 10);                 // causale | riga
            $table->timestamps();
            $table->index(['company_id', 'kind']);
        });
        Schema::create('catalog_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('label', 60)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_units');
        Schema::dropIfExists('catalog_snippets');
        Schema::dropIfExists('catalog_articles');
    }
};
