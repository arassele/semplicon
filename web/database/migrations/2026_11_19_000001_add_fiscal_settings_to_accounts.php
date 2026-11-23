<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Piano dei conti 2026 con impostazioni fiscali per conto; tabella dei parametri fiscali. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('voce_cee', 10)->nullable();
            $table->string('categoria', 60)->nullable();
            $table->boolean('iva_rilevante')->default(true);
            $table->decimal('iva_detraibile_pct', 5, 2)->nullable();   // Suggerimento per % detraibile nella registrazione
            $table->string('iva_nota', 160)->nullable();
            $table->decimal('deducibilita_pct', 5, 2)->nullable();     // per la successiva dichiarazione dei redditi, per ora descrittivo
            $table->string('deducibilita_nota', 160)->nullable();
            $table->string('irap_nota', 160)->nullable();
            $table->string('limite', 255)->nullable();
            $table->string('normativa', 255)->nullable();
            $table->text('note')->nullable();
            $table->string('source', 10)->default('manuale');          // modello | manuale
        });

        Schema::create('fiscal_parameters', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year');
            $table->string('ambito', 160);
            $table->string('deducibilita', 160)->nullable();
            $table->string('iva', 160)->nullable();
            $table->string('limiti', 255)->nullable();
            $table->string('riferimento', 255)->nullable();
            $table->string('fonte_url', 500)->nullable();
            $table->timestamps();
        });

        $path = database_path('data/parametri_fiscali_2026.csv');
        if (is_file($path)) {
            $fh = fopen($path, 'r');
            fgetcsv($fh, null, ';', '"', '');
            $now = now();
            while (($r = fgetcsv($fh, null, ';', '"', '')) !== false) {
                DB::table('fiscal_parameters')->insert(['year' => 2026, 'ambito' => $r[0], 'deducibilita' => $r[1] ?: null, 'iva' => $r[2] ?: null,
                    'limiti' => $r[3] ?: null, 'riferimento' => $r[4] ?: null, 'fonte_url' => $r[5] ?: null, 'created_at' => $now, 'updated_at' => $now]);
            }
            fclose($fh);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_parameters');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['voce_cee', 'categoria', 'iva_rilevante', 'iva_detraibile_pct', 'iva_nota', 'deducibilita_pct', 'deducibilita_nota', 'irap_nota', 'limite', 'normativa', 'note', 'source']);
        });
    }
};
