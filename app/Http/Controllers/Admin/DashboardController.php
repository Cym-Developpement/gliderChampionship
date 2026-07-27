<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Participant;
use App\Models\Pilot;
use App\Models\Turnpoint;

class DashboardController extends Controller
{
    public function index()
    {
        $comp    = Competition::latest('id')->first();
        $days    = $comp ? $comp->days()->orderBy('day_number')->get() : collect();
        $activeDay = $comp ? $comp->activeDay() : null;

        return view('admin.dashboard', [
            'competitionCount' => Competition::count(),
            'pilotCount'       => Pilot::count(),
            'participantCount' => Participant::count(),
            'turnpointCount'   => Turnpoint::count(),
            'comp'             => $comp,
            'days'             => $days,
            'activeDay'        => $activeDay,
        ]);
    }
}
