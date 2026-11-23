<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catena di hash nel registro di audit (una catena per azienda, gli eventi senza azienda formano una propria): ogni voce contiene l'hash della precedente. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn(['prev_hash', 'hash']);
        });
    }
};
