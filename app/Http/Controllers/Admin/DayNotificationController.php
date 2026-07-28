<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\DayRecapMail;
use App\Models\CompetitionDay;
use App\Models\Pilot;
use App\Services\DayRecapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envoi manuel du récapitulatif de journée aux pilotes.
 *
 * Déclenchement explicite : l'organisateur décide du moment, en général une
 * fois les traces contrôlées, pour ne pas diffuser des scores provisoires.
 */
class DayNotificationController extends Controller
{
    public function index(CompetitionDay $day)
    {
        return view('admin.days.notify', [
            'day'        => $day,
            'competition' => $day->competition,
            'recaps'     => $this->recaps($day),
            'mailer'     => config('mail.default'),
        ]);
    }

    public function send(Request $request, CompetitionDay $day)
    {
        $request->validate([
            'pilots'   => 'nullable|array',
            'pilots.*' => 'exists:pilots,id',
        ]);

        $selection = $request->input('pilots', []);
        $competition = $day->competition;

        $sent    = 0;
        $skipped = 0;
        $failed  = [];

        foreach ($this->recaps($day) as $recap) {
            $pilot = $recap['pilot'];

            if ($selection !== [] && !in_array((string) $pilot->id, array_map('strval', $selection), true)) {
                continue;
            }

            if (!$pilot->email) {
                $skipped++;
                continue;
            }

            try {
                Mail::to($pilot->email)->send(new DayRecapMail($recap, $competition, $day));
                $sent++;
            } catch (\Throwable $ex) {
                // Un destinataire fautif ne doit pas interrompre la série.
                $failed[] = $pilot->name;
                Log::error('Envoi du récapitulatif échoué', [
                    'pilot' => $pilot->name,
                    'day'   => $day->day_number,
                    'error' => $ex->getMessage(),
                ]);
            }
        }

        $message = "{$sent} récapitulatif(s) envoyé(s)";
        if ($skipped > 0) {
            $message .= ", {$skipped} pilote(s) sans adresse";
        }
        $message .= '.';

        $redirect = redirect()->route('admin.days.notify', $day);

        return $failed === []
            ? $redirect->with('success', $message)
            : $redirect->with('error', $message . ' Échec pour : ' . implode(', ', $failed) . '.');
    }

    /**
     * Récapitulatifs de tous les pilotes de la compétition, triés par total.
     *
     * @return list<array>
     */
    private function recaps(CompetitionDay $day): array
    {
        $competition = $day->competition;

        $recaps = Pilot::where('competition_id', $competition->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Pilot $pilot) => DayRecapService::build($pilot, $competition, $day))
            ->all();

        usort($recaps, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $recaps;
    }
}
