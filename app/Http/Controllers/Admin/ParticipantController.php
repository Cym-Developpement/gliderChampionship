<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Participant;
use App\Models\Pilot;
use Illuminate\Http\Request;

class ParticipantController extends Controller
{
    public function index()
    {
        $participants = Participant::with('competition')->orderBy('name')->paginate(20);
        return view('admin.participants.index', compact('participants'));
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
