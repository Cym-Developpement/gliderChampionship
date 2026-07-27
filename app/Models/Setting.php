<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'competition_id'];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public static function get(string $key, ?int $competitionId = null): ?string
    {
        if ($competitionId !== null) {
            $value = static::where('key', $key)
                ->where('competition_id', $competitionId)
                ->value('value');

            if ($value !== null) {
                return $value;
            }
        }

        return static::where('key', $key)
            ->whereNull('competition_id')
            ->value('value');
    }
}
