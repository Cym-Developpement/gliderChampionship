<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Participant;
use App\Models\Pilot;
use App\Services\CsvImporter;
use Illuminate\Http\Request;

class ParticipantController extends Controller
{
    public function index()
    {
        $participants = Participant::with('competition')->orderBy('name')->paginate(20);
        $competitions = Competition::orderBy('id')->get();
        return view('admin.participants.index', compact('participants', 'competitions'));
    }

    /**
     * Import de planeurs depuis un export CSV d'inscriptions.
     *
     * Colonnes requises : « Immatriculation », « Marque » et « Modèle ».
     * « Pilote propriétaire », si présente, sert à rattacher le planeur au
     * pilote déjà enregistré dans la compétition. Les autres colonnes
     * (type, e-mail, date) sont ignorées.
     */
    public function import(Request $request)
    {
        $request->validate([
            'csv'            => 'required|file|max:2048',
            'competition_id' => 'required|exists:competitions,id',
        ], [], [
            'csv' => 'fichier CSV',
        ]);

        $rows = CsvImporter::read($request->file('csv')->getRealPath(), ['immatriculation']);

        if ($rows === null) {
            return back()->withErrors(['csv' => "En-tête illisible : la colonne « Immatriculation » est introuvable."]);
        }

        $competitionId = (int) $request->input('competition_id');

        // Index des pilotes de la compétition, pour le rattachement par nom.
        $pilotsByName = Pilot::where('competition_id', $competitionId)
            ->get()
            ->keyBy(fn ($pilot) => mb_strtolower($pilot->name));

        $created = 0;
        $skipped = 0;
        $invalid = 0;
        $linked  = 0;

        foreach ($rows as $row) {
            $reg = CsvImporter::registration($row['immatriculation'] ?? '');

            if ($reg === '') {
                $invalid++;
                continue;
            }

            // Rapprochement sur l'immatriculation, indépendamment de sa ponctuation.
            $key = CsvImporter::registrationKey($reg);
            $exists = Participant::where('competition_id', $competitionId)
                ->get(['id', 'reg'])
                ->first(fn ($p) => CsvImporter::registrationKey((string) $p->reg) === $key);

            if ($exists) {
                $skipped++;
                continue;
            }

            $owner = CsvImporter::personName('', $row['piloteproprietaire'] ?? '');

            $participant = Participant::create([
                'competition_id' => $competitionId,
                'external_id'    => $this->uniqueExternalId($key),
                'name'           => $owner !== '' ? $owner : $reg,
                'reg'            => $reg,
                'glider_brand'   => $row['marque'] ?? null,
                'glider_model'   => $row['modele'] ?? null,
            ]);
            $created++;

            // Rattachement au pilote homonyme, s'il existe déjà.
            $pilot = $pilotsByName->get(mb_strtolower($owner));
            if ($pilot) {
                $participant->pilots()->syncWithoutDetaching([$pilot->id]);
                $linked++;
            }
        }

        $message = "Import terminé : {$created} planeur(s) créé(s), {$skipped} déjà présent(s), {$linked} rattaché(s) à un pilote";
        $message .= $invalid > 0 ? ", {$invalid} ligne(s) sans immatriculation." : '.';

        return redirect()->route('admin.participants.index')->with('success', $message);
    }

    /**
     * external_id est unique et non nul : on part de l'immatriculation, qui
     * identifie naturellement le planeur, et on suffixe en cas de collision
     * (même machine engagée sur deux compétitions).
     */
    private function uniqueExternalId(string $base): string
    {
        $base = $base !== '' ? $base : 'PLANEUR';
        $candidate = $base;
        $suffix = 2;

        while (Participant::where('external_id', $candidate)->exists()) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    public function create()
    {
        $competitions = Competition::all();
        $pilots = Pilot::orderBy('name')->get();
        return view('admin.participants.create', compact('competitions', 'pilots'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'external_id' => 'required|string|max:255|unique:participants,external_id',
            'name' => 'required|string|max:255',
            'ogn_id' => 'nullable|string|max:255',
            'reg' => 'nullable|string|max:255',
            'glider_brand' => 'nullable|string|max:255',
            'glider_model' => 'nullable|string|max:255',
            'competition_id' => 'required|exists:competitions,id',
            'handicap' => 'nullable|numeric|min:0|max:99.99',
            'pilots' => 'nullable|array',
            'pilots.*' => 'exists:pilots,id',
        ]);

        $participant = Participant::create(collect($data)->except('pilots')->toArray());

        if (!empty($data['pilots'])) {
            $participant->pilots()->sync($data['pilots']);
        }

        return redirect()->route('admin.participants.index')->with('success', 'Participant créé.');
    }

    public function edit(Participant $participant)
    {
        $competitions = Competition::all();
        $pilots = Pilot::orderBy('name')->get();
        $participant->load('pilots');
        return view('admin.participants.edit', compact('participant', 'competitions', 'pilots'));
    }

    public function update(Request $request, Participant $participant)
    {
        $data = $request->validate([
            'external_id' => 'required|string|max:255|unique:participants,external_id,' . $participant->id,
            'name' => 'required|string|max:255',
            'ogn_id' => 'nullable|string|max:255',
            'reg' => 'nullable|string|max:255',
            'glider_brand' => 'nullable|string|max:255',
            'glider_model' => 'nullable|string|max:255',
            'competition_id' => 'required|exists:competitions,id',
            'handicap' => 'nullable|numeric|min:0|max:99.99',
            'pilots' => 'nullable|array',
            'pilots.*' => 'exists:pilots,id',
        ]);

        $participant->update(collect($data)->except('pilots')->toArray());
        $participant->pilots()->sync($data['pilots'] ?? []);

        return redirect()->route('admin.participants.index')->with('success', 'Participant mis à jour.');
    }

    public function destroy(Participant $participant)
    {
        $participant->delete();

        return redirect()->route('admin.participants.index')->with('success', 'Participant supprimé.');
    }
}
