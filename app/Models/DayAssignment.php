<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Planeur affecté à un pilote pour une journée de compétition.
 * Surcharge, pour ce jour-là, l'association globale participant_pilot.
 */
class DayAssignment extends Model
{
    protected $fillable = [
        'competition_day_id',
        'pilot_id',
        'participant_id',
    ];

    public function competitionDay()
    {
        return $this->belongsTo(CompetitionDay::class);
    }

    public function pilot()
    {
        return $this->belongsTo(Pilot::class);
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }
}
