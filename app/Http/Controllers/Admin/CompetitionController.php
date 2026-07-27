<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use Illuminate\Http\Request;

class CompetitionController extends Controller
{
    public function edit()
    {
        $competition = Competition::first();
        $days = $competition ? $competition->days()->orderBy('day_number')->get() : collect();
        return view('admin.competition.edit', compact('competition', 'days'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'start_name' => 'required|string|max:255',
            'start_lat' => 'required|numeric|between:-90,90',
            'start_lng' => 'required|numeric|between:-180,180',
            'start_radius_km' => 'required|integer|min:0',
        ]);

        $competition = Competition::first();

        if ($competition) {
            $competition->update($data);
        } else {
            Competition::create($data);
        }

        return redirect()->route('admin.competition.edit')->with('success', 'Compétition mise à jour.');
    }

    public function activate()
    {
        $competition = Competition::firstOrFail();
        $competition->update(['status' => 'active']);

        return redirect()->route('admin.competition.edit')->with('success', 'Compétition activée.');
    }

    public function closeCompetition()
    {
        $competition = Competition::firstOrFail();
        $competition->update(['status' => 'closed']);

        return redirect()->route('admin.competition.edit')->with('success', 'Compétition clôturée.');
    }
}
