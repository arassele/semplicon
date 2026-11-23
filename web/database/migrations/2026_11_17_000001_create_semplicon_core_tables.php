<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * I00/I01: mandanti, appartenenze, profili IVA, audit.
 * Volutamente senza tipi specifici del database, affinché PostgreSQL e MySQL/MariaDB
 * funzionino allo stesso modo (vedi docs/DECISIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Anagrafica dell'azienda («Dati azienda»).
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                   // Denominazione ovvero «Cognome Nome»
            $table->string('partita_iva', 11)->unique();
            $table->string('codice_fiscale', 16)->nullable();
            $table->string('tipologia', 24)->default('ditta_individuale'); // App\Enums\CompanyType
            $table->string('natura_giuridica', 120)->nullable();
            $table->string('sigla', 60)->nullable();
            $table->string('codice_sdi', 7)->nullable();             // Codice destinatario fatturazione elettronica
            $table->string('titolo', 20)->nullable();
            $table->string('cognome', 80)->nullable();
            $table->string('nome', 80)->nullable();
            $table->string('sesso', 1)->nullable();                  // M / F
            $table->date('data_nascita')->nullable();
            $table->string('comune_nascita', 120)->nullable();
            $table->string('provincia_nascita', 2)->nullable();
            $table->string('indirizzo', 160)->nullable();
            $table->string('numero_civico', 12)->nullable();
            $table->string('cap', 5)->nullable();
            $table->string('comune', 120)->nullable();
            $table->string('provincia', 2)->nullable();
            $table->string('nazione', 2)->default('IT');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('company_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                               // App\Enums\ContactType
            $table->string('value', 190);
            $table->string('description', 120)->nullable();
            $table->boolean('is_default')->default(false);            // Predefinito per la corrispondenza
            $table->timestamps();
        });

        Schema::create('company_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 8);                                // ATECO, es. 69.20.11
            $table->string('description', 200);
            $table->boolean('is_primary')->default(false);            // Attività prevalente
            $table->timestamps();
        });

        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('iban', 34);
            $table->string('bank_name', 120)->nullable();
            $table->string('description', 120)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        // Regime IVA per azienda e anno solare; le correzioni come nuova versione dello stesso anno.
        Schema::create('vat_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('version');
            $table->string('status', 16);                  // App\Enums\ProfileStatus
            $table->string('regime', 32)->nullable();      // App\Enums\VatRegime
            $table->string('method', 16)->nullable();      // App\Enums\SemplificataMethod, solo con contabilità semplificata
            $table->boolean('forfettario_iva')->default(false); // Soggetto forfettario con IVA
            $table->string('periodicity', 16)->nullable(); // App\Enums\Periodicity
            $table->decimal('pro_rata', 5, 2)->default(100); // Aliquota pro rata: quota detraibile dell'IVA sugli acquisti in %
            $table->string('rule_version', 32);
            $table->text('notes')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'year', 'version']);
            $table->index(['company_id', 'year', 'status']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('vat_profiles');
        Schema::dropIfExists('company_bank_accounts');
        Schema::dropIfExists('company_activities');
        Schema::dropIfExists('company_contacts');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('companies');
    }
};
