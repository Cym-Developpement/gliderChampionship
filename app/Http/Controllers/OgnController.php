<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Competition;
use App\Models\Participant;

class OgnController extends Controller
{
    /**
     * Calcule les bounds (latMax, latMin, lonMax, lonMin) à partir d'un centre et rayon en km.
     */
    public static function computeBounds(float $lat, float $lng, float $radiusKm): array
    {
        $degLatPerKm = 1.0 / 111.32;
        $degLngPerKm = 1.0 / (111.32 * max(0.000001, cos(deg2rad($lat))));
        $dLat = $radiusKm * $degLatPerKm;
        $dLng = $radiusKm * $degLngPerKm;
        return [
            'latMax' => $lat + $dLat,
            'latMin' => $lat - $dLat,
            'lonMax' => $lng + $dLng,
            'lonMin' => $lng - $dLng,
        ];
    }

    /**
     * Récupère les positions OGN pour les bounds donnés.
     * @param array $bounds ['latMax','latMin','lonMax','lonMin']
     * @return array Liste d'items [{lat, lng, id, reg, alt_raw, ground_speed, hdg, climb, name}]
     */
    public static function fetchPositions(array $bounds): array
    {
        // Mode développement: positions factices via cache
        if (env('DEV_FAKE_POSITIONS', false)) {
            $cached = Cache::store('file')->get('dev_fake_positions');
            if ($cached) {
                return $cached;
            }

            $comp = Competition::latest('id')->first();
            if (!$comp || !$comp->start_lat || !$comp->start_lng) {
                return [];
            }
            $centerLat = (float)$comp->start_lat;
            $centerLng = (float)$comp->start_lng;
            $radiusKmMax = 20.0;
            $participants = Participant::when($comp, fn($q) => $q->where('competition_id', $comp->id))->get();

            $items = [];
            foreach ($participants as $p) {
                $seed = crc32((string)($p->external_id ?: $p->id));
                $u1 = (($seed & 0xFFFF) / 0xFFFF);
                $u2 = ((($seed >> 16) & 0xFFFF) / 0xFFFF);
                $angle = $u1 * 360.0;
                $rKm = sqrt($u2) * $radiusKmMax;
                $dLat = $rKm / 111.32;
                $dLng = $rKm / (111.32 * max(0.000001, cos(deg2rad($centerLat))));
                $lat = $centerLat + $dLat * cos(deg2rad($angle));
                $lng = $centerLng + $dLng * sin(deg2rad($angle));
                $alt = 500 + ($seed % 2501);
                $gsFake = 75 + (($seed >> 8) % 56);
                $climbFake = -4.0 + ((($seed >> 16) % 91) / 10.0);

                $items[] = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'id' => $p->external_id ?: ($p->reg ?: (string)$p->id),
                    'reg' => $p->reg,
                    'alt_raw' => (int)$alt,
                    'ground_speed' => (int)$gsFake,
                    'hdg' => (int)round($angle) % 360,
                    'climb' => (float)$climbFake,
                    'name' => $p->name,
                    'participant_id' => $p->id,
                ];
            }

            Cache::store('file')->put('dev_fake_positions', $items);
            return $items;
        }

        $ognUrl = 'http://live.glidernet.org/lxml.php';
        $query = [
            'a' => '0',
            'b' => $bounds['latMax'],
            'c' => $bounds['latMin'],
            'd' => $bounds['lonMax'],
            'e' => $bounds['lonMin'],
            'z' => '2',
        ];

        try {
            $resp = Http::timeout(6)->get($ognUrl, $query);
            if (!$resp->ok()) {
                return [];
            }
            $xml = @simplexml_load_string($resp->body());
            if ($xml === false) {
                return [];
            }
            $out = [];
            foreach ($xml->m as $m) {
                $a = (string)($m['a'] ?? '');
                if ($a === '') { continue; }
                $parts = explode(',', $a);
                if (count($parts) < 2) { continue; }
                $lat = (float)$parts[0];
                $lng = (float)$parts[1];
                $reg = $parts[3] ?? '';
                $alt = isset($parts[4]) ? (int)$parts[4] : null;
                $gs = isset($parts[8]) ? (float)$parts[8] : null;
                $hdg = isset($parts[7]) ? (int)$parts[7] : null;
                $climb = isset($parts[9]) ? (float)$parts[9] : null;
                $name = $parts[11] ?? '';
                $hex = $parts[13] ?? '';
                $out[] = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'id' => $hex !== '' ? $hex : $reg,
                    'reg' => $reg,
                    'alt_raw' => $alt,
                    'ground_speed' => $gs,
                    'hdg' => $hdg,
                    'climb' => $climb,
                    'name' => $name,
                ];
            }
            if (!empty($out)) {
                Log::channel('ogn')->info('OGN positions', ['count' => count($out), 'items' => $out]);
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('OGN proxy error: '.$e->getMessage());
            return [];
        }
    }

    /**
     * Met à jour la position d'un participant fake dans le cache.
     */
    public static function updateFakePosition(int $participantId, float $lat, float $lng): bool
    {
        $items = Cache::store('file')->get('dev_fake_positions');
        if (!$items) {
            return false;
        }
        $found = false;
        foreach ($items as &$item) {
            if (($item['participant_id'] ?? null) === $participantId) {
                $item['lat'] = $lat;
                $item['lng'] = $lng;
                $found = true;
                break;
            }
        }
        unset($item);
        if ($found) {
            Cache::store('file')->put('dev_fake_positions', $items);
        }
        return $found;
    }

    /**
     * Proxy vers OGN lxml.php pour récupérer les positions.
     */
    public function positions(Request $request)
    {
        // Paramètres mode centre+carré
        $centerLat = $request->query('lat');
        $centerLng = $request->query('lng');
        $sizeKm = $request->query('sizeKm');
        $radiusKm = $request->query('radiusKm');

        // Paramètres mode bounds direct
        $latMax = $request->query('b');
        $latMin = $request->query('c');
        $lonMax = $request->query('d');
        $lonMin = $request->query('e');

        // Si centre + rayon/carré fournis, calcule les bornes
        if ($centerLat !== null && $centerLng !== null && ($sizeKm !== null || $radiusKm !== null)) {
            $halfSideKm = $sizeKm !== null ? ((float) $sizeKm) / 2.0 : (float) $radiusKm;
            $computed = self::computeBounds((float) $centerLat, (float) $centerLng, $halfSideKm);
            $latMax = $computed['latMax'];
            $latMin = $computed['latMin'];
            $lonMax = $computed['lonMax'];
            $lonMin = $computed['lonMin'];
        }

        if ($latMax === null || $latMin === null || $lonMax === null || $lonMin === null) {
            return response()->json(['error' => 'Missing parameters. Provide (lat,lng and sizeKm|radiusKm) or b,c,d,e'], 400);
        }

        $bounds = [
            'latMax' => $latMax,
            'latMin' => $latMin,
            'lonMax' => $lonMax,
            'lonMin' => $lonMin,
        ];

        $items = self::fetchPositions($bounds);
        return response()->json(['items' => $items]);
    }
}


