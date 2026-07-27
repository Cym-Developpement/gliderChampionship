<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turnpoint extends Model
{
    protected $fillable = [
        'competition_id',
        'name',
        'lat',
        'lng',
        'radius_m',
        'points',
        'order',
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }
}
