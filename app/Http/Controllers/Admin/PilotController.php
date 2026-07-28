<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Pilot;
use App\Services\CsvImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PilotController extends Controller
{
    public function index()
    {
        $pilots = Pilot::with('competition')->orderBy('name')->paginate(20);
        $competitions = Competition::orderBy('id')->get();
        return view('admin.pilots.index', compact('pilots', 'competitions'));
    }

    public function create()
    {
        $competitions = Competition::all();
        return view('admin.pilots.create', compact('competitions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'callsign' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'competition_id' => 'required|exists:competitions,id',
            'photo' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('pilots', 'public');
        }

        unset($data['photo']);
        Pilot::create($data);

        return redirect()->route('admin.pilots.index')->with('success', 'Pilote créé.');
    }

    public function edit(Pilot $pilot)
    {
        $competitions = Competition::all();
        return view('admin.pilots.edit', compact('pilot', 'competitions'));
    }

    public function update(Request $request, Pilot $pilot)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'callsign' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'competition_id' => 'required|exists:competitions,id',
            'photo' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('photo')) {
            if ($pilot->photo_path) {
                Storage::disk('public')->delete($pilot->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('pilots', 'public');
        }

        unset($data['photo']);
        $pilot->update($data);

        return redirect()->route('admin.pilots.index')->with('success', 'Pilote mis à jour.');
    }

    /**
     * Import de pilotes depuis un export CSV d'inscriptions.
     *
     * Format attendu : séparateur « ; », guillemets pour les champs contenant
     * un séparateur, et une ligne d'en-tête contenant au moins « Nom » et
     * « Prénom ». Les autres colonnes sont ignorées.
     */
    public function import(Request $request)
    {
        $request->validate([
            'csv'            => 'required|file|max:2048',
            'competition_id' => 'required|exists:competitions,id',
        ], [], [
            'csv' => 'fichier CSV',
        ]);

        $rows = CsvImporter::read($request->file('csv')->getRealPath(), ['nom', 'prenom']);

        if ($rows === null) {
            return back()->withErrors(['csv' => "En-tête illisible : les colonnes « Nom » et « Prénom » sont introuvables."]);
        }

        $competitionId = (int) $request->input('competition_id');
        $created = 0;
        $skipped = 0;
        $updated = 0;
        $invalid = 0;

        // Un même pilote peut figurer plusieurs fois — inscription reprise,
        // adresse corrigée. La dernière ligne l'emporte, sinon les adresses
        // basculeraient de l'une à l'autre à chaque import.
        $unique = [];
        foreach ($rows as $row) {
            $name = CsvImporter::personName($row['prenom'] ?? '', $row['nom'] ?? '');

            if ($name === '') {
                $invalid++;
                continue;
            }

            $unique[mb_strtolower($name)] = ['name' => $name, 'row' => $row];
        }

        $duplicates = count($rows) - $invalid - count($unique);

        foreach ($unique as ['name' => $name, 'row' => $row]) {
            // Colonne « Email » du fichier d'inscription, indispensable à
            // l'envoi du récapitulatif de journée.
            $email = trim($row['email'] ?? '');
            $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;

            // Rapprochement insensible à la casse, dans la compétition visée.
            $existing = Pilot::where('competition_id', $competitionId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($existing) {
                $skipped++;

                // La fiche est conservée, mais l'adresse est rafraîchie : un
                // ré-import est le moyen le plus simple de récupérer les
                // adresses d'une liste d'inscription mise à jour.
                if ($email !== null && $existing->email !== $email) {
                    $existing->update(['email' => $email]);
                    $updated++;
                }

                continue;
            }

            Pilot::create([
                'competition_id' => $competitionId,
                'name'           => $name,
                'email'          => $email,
            ]);
            $created++;
        }

        $message = "Import terminé : {$created} pilote(s) créé(s), {$skipped} déjà présent(s)";
        if ($updated > 0) {
            $message .= " dont {$updated} adresse(s) mise(s) à jour";
        }
        if ($duplicates > 0) {
            $message .= ", {$duplicates} ligne(s) en double";
        }
        $message .= $invalid > 0 ? ", {$invalid} ligne(s) sans nom exploitable." : '.';

        return redirect()->route('admin.pilots.index')->with('success', $message);
    }

    public function destroy(Pilot $pilot)
    {
        if ($pilot->photo_path) {
            Storage::disk('public')->delete($pilot->photo_path);
        }
        $pilot->delete();

        return redirect()->route('admin.pilots.index')->with('success', 'Pilote supprimé.');
    }
}
