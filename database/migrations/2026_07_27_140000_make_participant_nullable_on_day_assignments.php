<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rend participant_id facultatif sur day_assignments.
 *
 * L'absence de ligne signifiait « pas de choix explicite », donc repli sur
 * l'association globale : désaffecter un planeur restait sans effet. Une ligne
 * dont le participant est nul exprime désormais « ce pilote ne vole pas ce
 * jour-là », ce que le repli ne doit pas contredire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_assignments', function (Blueprint $table) {
            $table->foreignId('participant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Les lignes sans planeur n'ont pas d'équivalent dans l'ancien schéma.
        DB::table('day_assignments')->whereNull('participant_id')->delete();

        Schema::table('day_assignments', function (Blueprint $table) {
            $table->foreignId('participant_id')->nullable(false)->change();
        });
    }
};
