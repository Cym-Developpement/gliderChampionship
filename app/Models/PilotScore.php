<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PilotScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'pilot_id', 'competition_day_id', 'points', 'is_validated', 'measured_at',
    ];

    protected $casts = [
        'measured_at' => 'datetime',
    ];

    /**
     * Score de référence d'un pilote pour une journée, doublons supprimés.
     *
     * D'anciennes clôtures répétées ont pu laisser plusieurs lignes. Les
     * lecteurs prenant la plus récente et certains écrivains la première
     * venue, une modification pouvait s'appliquer à une ligne invisible :
     * la validation semblait alors se défaire toute seule. On ne garde donc
     * que la plus récente.
     */
    public static function consolidate(int $pilotId, int $competitionDayId): ?self
    {
        $scores = static::where('pilot_id', $pilotId)
            ->where('competition_day_id', $competitionDayId)
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->get();

        if ($scores->isEmpty()) {
            return null;
        }

        $keep = $scores->shift();

        if ($scores->isNotEmpty()) {
            static::whereIn('id', $scores->pluck('id'))->delete();
        }

        return $keep;
    }

    public function pilot()
    {
        return $this->belongsTo(Pilot::class);
    }

    public function competitionDay()
    {
        return $this->belongsTo(CompetitionDay::class);
    }
}


