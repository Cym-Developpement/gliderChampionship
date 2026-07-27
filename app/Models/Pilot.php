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
}


