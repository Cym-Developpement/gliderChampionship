<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompetitionDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'competition_id', 'day_number', 'date', 'status', 'started_at', 'closed_at',
    ];

    protected $casts = [
        'date' => 'date',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function pilotScores()
    {
        return $this->hasMany(PilotScore::class);
    }

    public function pilotTurnpoints()
    {
        return $this->hasMany(PilotTurnpoint::class);
    }
}
