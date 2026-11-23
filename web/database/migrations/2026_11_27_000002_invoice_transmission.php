<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stato di trasmissione delle fatture emesse: la trasmissione al SdI avviene al di fuori di Semplicon, qui viene solo annotata. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('transmitted_at')->nullable();
            $table->foreignId('transmitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transmission_note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transmitted_by');
            $table->dropColumn(['transmitted_at', 'transmission_note']);
        });
    }
};
