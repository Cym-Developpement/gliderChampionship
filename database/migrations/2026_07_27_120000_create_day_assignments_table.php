<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affectation d'un planeur à un pilote pour une journée donnée.
 *
 * Le pivot participant_pilot reste l'association par défaut sur toute la
 * compétition ; cette table la surcharge jour par jour, ce qui permet à un
 * pilote de changer de machine — et donc de handicap — d'une journée à l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pilot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Un seul planeur par pilote et par jour. Rien n'interdit en revanche
            // qu'un même planeur porte deux pilotes le même jour : c'est le cas
            // des biplaces.
            $table->unique(['competition_day_id', 'pilot_id']);
            $table->index(['competition_day_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_assignments');
    }
};
