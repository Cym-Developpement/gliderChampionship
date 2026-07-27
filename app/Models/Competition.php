<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Competition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'start_name', 'start_lat', 'start_lng', 'start_radius_km', 'status',
    ];

    public function participants()
    {
        return $this->hasMany(Participant::class);
    }

    public function turnpoints()
    {
        return $this->hasMany(Turnpoint::class)->orderBy('order');
    }

    public function days()
    {
        return $this->hasMany(CompetitionDay::class);
    }

    public function activeDay()
    {
        return $this->days()->where('status', 'active')->first();
    }
}


