<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adresse du pilote, pour l'envoi du récapitulatif de journée.
 *
 * Facultative : un pilote sans adresse reste parfaitement utilisable, il est
 * simplement écarté de l'envoi et signalé comme tel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pilots', function (Blueprint $table) {
            $table->string('email')->nullable()->after('callsign');
        });
    }

    public function down(): void
    {
        Schema::table('pilots', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
