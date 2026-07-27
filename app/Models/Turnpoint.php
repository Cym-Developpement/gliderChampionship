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

    /**
     * Rayon de validation de la balise, en mètres.
     *
     * Une seule source de vérité : le réglage proximity_near_m, celui-là même
     * qui déclenche la validation en vol. La colonne radius_m ne sert plus
     * qu'à surcharger ponctuellement une balise, faute de quoi le contrôle IGC
     * pourrait infirmer ce que la carte a validé.
     *
     * Le défaut de 1000 m est celui du JavaScript de la carte.
     */
    public function validationRadiusM(): int
    {
        $setting = Setting::get('proximity_near_m', $this->competition_id);

        if ($setting !== null && (int) $setting > 0) {
            return (int) $setting;
        }

        return (int) ($this->radius_m ?: 1000);
    }
}
