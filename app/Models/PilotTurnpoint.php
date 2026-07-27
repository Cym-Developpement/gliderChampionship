<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PilotTurnpoint extends Model
{
    protected $fillable = [
        'pilot_id',
        'turnpoint_id',
        'competition_day_id',
        'validated_at',
        'source',
        'igc_distance_m',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    public function pilot()
    {
        return $this->belongsTo(Pilot::class);
    }

    public function turnpoint()
    {
        return $this->belongsTo(Turnpoint::class);
    }

    public function competitionDay()
    {
        return $this->belongsTo(CompetitionDay::class);
    }
}
