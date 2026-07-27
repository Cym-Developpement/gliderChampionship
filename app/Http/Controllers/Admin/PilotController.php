<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Pilot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PilotController extends Controller
{
    public function index()
    {
        $pilots = Pilot::with('competition')->orderBy('name')->paginate(20);
        return view('admin.pilots.index', compact('pilots'));
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

    public function destroy(Pilot $pilot)
    {
        if ($pilot->photo_path) {
            Storage::disk('public')->delete($pilot->photo_path);
        }
        $pilot->delete();

        return redirect()->route('admin.pilots.index')->with('success', 'Pilote supprimé.');
    }
}
