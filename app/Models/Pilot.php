<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pilot extends Model
{
    use HasFactory;

    protected $fillable = [
        'competition_id', 'name', 'callsign', 'photo_path', 'reg', 'glider_brand', 'glider_model',
    ];

    protected $appends = ['photo_url'];

    public function getPhotoUrlAttribute(): string
    {
        if (!$this->photo_path) return asset('pilote-picture.png');
        return asset('storage/'.$this->photo_path);
    }

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function scores()
    {
        return $this->hasMany(PilotScore::class);
    }

    public function participants()
    {
        return $this->belongsToMany(Participant::class);
    }

    public function pilotTurnpoints()
    {
        return $this->hasMany(PilotTurnpoint::class);
    }

    public function dayAssignments()
    {
        return $this->hasMany(DayAssignment::class);
    }

    /**
     * Planeur du pilote pour une journée donnée.
     *
     * L'affectation du jour prime ; à défaut on retombe sur l'association
     * globale participant_pilot, ce qui préserve le fonctionnement des
     * compétitions où le pilote garde la même machine.
     */
    public function participantForDay(?CompetitionDay $day, ?int $competitionId = null): ?Participant
    {
        $competitionId ??= $this->competition_id;

        if ($day) {
            $assignment = DayAssignment::where('competition_day_id', $day->id)
                ->where('pilot_id', $this->id)
                ->with('participant')
                ->first();

            if ($assignment && $assignment->participant) {
                return $assignment->participant;
            }
        }

        return $this->participants()
            ->when($competitionId, fn($q) => $q->where('competition_id', $competitionId))
            ->first();
    }
}


