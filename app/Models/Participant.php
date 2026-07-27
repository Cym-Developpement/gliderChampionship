<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Participant extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id', 'name', 'ogn_id', 'reg', 'glider_brand', 'glider_model', 'competition_id', 'handicap',
    ];

    public function scores()
    {
        return $this->hasMany(Score::class);
    }

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function pilots()
    {
        return $this->belongsToMany(Pilot::class);
    }
}


