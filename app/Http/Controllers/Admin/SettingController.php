<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::with('competition')->orderBy('key')->orderBy('competition_id')->get();
        $competitions = Competition::orderBy('name')->get();

        return view('admin.settings.index', compact('settings', 'competitions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'key' => 'required|string|max:255',
            'value' => 'required|string',
            'competition_id' => 'nullable|exists:competitions,id',
        ]);

        $exists = Setting::where('key', $data['key'])
            ->where('competition_id', $data['competition_id'] ?? null)
            ->when(empty($data['competition_id']), fn ($q) => $q->whereNull('competition_id'))
            ->exists();

        if ($exists) {
            return back()->withErrors(['key' => 'Ce paramètre existe déjà pour cette compétition.'])->withInput();
        }

        Setting::create($data);

        return redirect()->route('admin.settings.index')->with('success', 'Paramètre créé.');
    }

    public function update(Request $request, Setting $setting)
    {
        $data = $request->validate([
            'value' => 'required|string',
        ]);

        $setting->update($data);

        return redirect()->route('admin.settings.index')->with('success', 'Paramètre mis à jour.');
    }

    public function destroy(Setting $setting)
    {
        $setting->delete();

        return redirect()->route('admin.settings.index')->with('success', 'Paramètre supprimé.');
    }
}
