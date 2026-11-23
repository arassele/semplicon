<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Anagrafica di clienti e fornitori per azienda. Un'anagrafica può essere entrambe le cose. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->string('kind', 8)->default('azienda');           // azienda | persona
            $table->string('name', 120)->nullable();
            $table->string('first_name', 60)->nullable();
            $table->string('last_name', 60)->nullable();
            $table->string('vat', 30)->nullable();                   // con prefisso del paese, es. IT01234567897
            $table->string('cf', 16)->nullable();
            $table->string('country', 2)->default('IT');
            $table->string('address', 80)->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('city', 60)->nullable();
            $table->string('province', 2)->nullable();
            $table->string('sdi_code', 7)->nullable();
            $table->string('pec', 255)->nullable();
            $table->boolean('is_pa')->default(false);
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('payment_mode', 4)->default('MP05');
            $table->unsignedSmallInteger('payment_days')->default(30);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'is_customer']);
            $table->index(['company_id', 'is_supplier']);
            $table->index(['company_id', 'vat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
