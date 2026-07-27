<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Turnpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TurnpointController extends Controller
{
    public function index()
    {
        $turnpoints = Turnpoint::with('competition')->orderBy('order')->paginate(20);
        return view('admin.turnpoints.index', compact('turnpoints'));
    }

    public function create()
    {
        $competitions = Competition::all();
        return view('admin.turnpoints.create', compact('competitions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius_m' => 'required|integer|min:0',
            'points' => 'required|integer|min:0',
            'order' => 'required|integer|min:0',
            'competition_id' => 'required|exists:competitions,id',
        ]);

        Turnpoint::create($data);

        return redirect()->route('admin.turnpoints.index')->with('success', 'Turnpoint créé.');
    }

    public function edit(Turnpoint $turnpoint)
    {
        $competitions = Competition::all();
        return view('admin.turnpoints.edit', compact('turnpoint', 'competitions'));
    }

    public function update(Request $request, Turnpoint $turnpoint)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius_m' => 'required|integer|min:0',
            'points' => 'required|integer|min:0',
            'order' => 'required|integer|min:0',
            'competition_id' => 'required|exists:competitions,id',
        ]);

        $turnpoint->update($data);

        return redirect()->route('admin.turnpoints.index')->with('success', 'Turnpoint mis à jour.');
    }

    public function destroy(Turnpoint $turnpoint)
    {
        $turnpoint->delete();

        return redirect()->route('admin.turnpoints.index')->with('success', 'Turnpoint supprimé.');
    }

    public function importCup(Request $request)
    {
        $request->validate([
            'cup_file' => 'required|file|mimes:cup,txt,csv',
            'competition_id' => 'required|exists:competitions,id',
        ]);

        $content = file_get_contents($request->file('cup_file')->getRealPath());
        $lines = preg_split('/\r\n|\r|\n/', $content);

        if (count($lines) < 2) {
            return back()->withErrors(['cup_file' => 'Fichier CUP vide ou invalide.']);
        }

        // Parse header to find column indices
        $header = str_getcsv(array_shift($lines));
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $colName = array_search('name', $header);
        $colLat = array_search('lat', $header);
        $colLon = array_search('lon', $header);

        if ($colName === false || $colLat === false || $colLon === false) {
            return back()->withErrors(['cup_file' => 'En-têtes manquants : name, lat, lon requis.']);
        }

        $competitionId = $request->input('competition_id');
        $imported = 0;

        DB::transaction(function () use ($lines, $colName, $colLat, $colLon, $header, $competitionId, &$imported) {
            $order = Turnpoint::where('competition_id', $competitionId)->max('order') ?? 0;

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '-----')) {
                    break; // Task section separator or empty line
                }

                $fields = str_getcsv($line);
                if (count($fields) <= max($colName, $colLat, $colLon)) {
                    continue;
                }

                $name = trim($fields[$colName], '"');
                $lat = $this->parseCupCoord($fields[$colLat]);
                $lng = $this->parseCupCoord($fields[$colLon]);

                if ($lat === null || $lng === null || $name === '') {
                    continue;
                }

                $order++;
                Turnpoint::create([
                    'competition_id' => $competitionId,
                    'name' => $name,
                    'lat' => $lat,
                    'lng' => $lng,
                    'radius_m' => 500,
                    'points' => 0,
                    'order' => $order,
                ]);
                $imported++;
            }
        });

        return redirect()->route('admin.turnpoints.index')
            ->with('success', "$imported turnpoint(s) importé(s) depuis le fichier CUP.");
    }

    /**
     * Parse CUP coordinate format (e.g. "4657.983N" or "00009.487E") to decimal degrees.
     */
    private function parseCupCoord(string $raw): ?float
    {
        $raw = trim($raw);
        if (!preg_match('/^(\d{2,3})(\d{2}\.\d+)([NSEW])$/', $raw, $m)) {
            return null;
        }

        $degrees = (int) $m[1];
        $minutes = (float) $m[2];
        $decimal = $degrees + ($minutes / 60);

        if ($m[3] === 'S' || $m[3] === 'W') {
            $decimal = -$decimal;
        }

        return round($decimal, 6);
    }
}
