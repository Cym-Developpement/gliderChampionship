<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OgnController;
use App\Models\Competition;
use App\Models\Participant;
use Illuminate\Http\Request;

class OgnAssignController extends Controller
{
    public function index()
    {
        $comp = Competition::latest('id')->first();

        if (!$comp || !$comp->start_lat || !$comp->start_lng) {
            return view('admin.ogn-assign.index', [
                'devices' => [],
                'participants' => collect(),
                'error' => 'Aucune compétition configurée avec des coordonnées de départ.',
            ]);
        }

        $radiusKm = $comp->start_radius_km ?: 50;
        $bounds = OgnController::computeBounds(
            (float) $comp->start_lat,
            (float) $comp->start_lng,
            (float) $radiusKm
        );

        $allDevices = OgnController::fetchPositions($bounds);

        // Reg normalisés et ogn_id des participants existants
        $existingParticipants = Participant::where('competition_id', $comp->id)->get();
        $existingRegs = $existingParticipants
            ->filter(fn($p) => $p->reg !== null && $p->reg !== '')
            ->map(fn($p) => strtoupper(trim($p->reg)))
            ->toArray();
        $existingOgnIds = $existingParticipants
            ->filter(fn($p) => $p->ogn_id !== null && $p->ogn_id !== '')
            ->map(fn($p) => strtolower(trim($p->ogn_id)))
            ->toArray();

        // Filtrer les devices dont ni le reg ni l'ogn_id ne matche un participant
        $unassigned = array_filter($allDevices, function ($device) use ($existingRegs, $existingOgnIds) {
            $reg = strtoupper(trim($device['reg'] ?? ''));
            $ognId = strtolower(trim($device['id'] ?? ''));
            if ($reg !== '' && in_array($reg, $existingRegs)) return false;
            if ($ognId !== '' && in_array($ognId, $existingOgnIds)) return false;
            return $reg !== '' || $ognId !== '';
        });

        $participants = Participant::where('competition_id', $comp->id)->orderBy('name')->get();

        return view('admin.ogn-assign.index', [
            'devices' => array_values($unassigned),
            'participants' => $participants,
            'error' => null,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'reg' => 'required|string',
            'ogn_id' => 'nullable|string',
            'participant_id' => 'required|exists:participants,id',
        ]);

        $participant = Participant::findOrFail($request->input('participant_id'));
        $participant->update([
            'reg' => $request->input('reg'),
            'ogn_id' => $request->input('ogn_id', ''),
        ]);

        return redirect()->route('admin.ogn-assign.index')
            ->with('success', "Device {$request->input('reg')} assigné au participant {$participant->name}.");
    }
}
