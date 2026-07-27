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

    public function pilot()
    {
        return $this->belongsTo(Pilot::class);
    }

    public function competitionDay()
    {
        return $this->belongsTo(CompetitionDay::class);
    }
}


