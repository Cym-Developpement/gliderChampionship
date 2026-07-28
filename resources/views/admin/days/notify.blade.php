@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Notifications — Jour {{ $day->day_number }} ({{ $day->date->format('d/m/Y') }})</h2>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.days.scores', $day) }}" class="btn btn-outline-primary">Scores</a>
        <a href="{{ route('admin.competition.edit') }}" class="btn btn-secondary">Retour</a>
    </div>
</div>

@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

@if($mailer === 'log')
    <div class="alert alert-warning">
        <strong>Aucun serveur d'envoi configuré</strong> — <code>MAIL_MAILER=log</code>.
        Les messages seront écrits dans <code>storage/logs/laravel.log</code> au lieu d'être
        expédiés. Renseignez les paramètres SMTP dans le <code>.env</code> avant l'envoi réel.
    </div>
@endif

<p class="text-muted">
    Chaque pilote reçoit ses balises validées, l'heure de passage et son total.
    Envoyez de préférence après contrôle des traces : le message indique
    explicitement si le score est provisoire.
</p>

<form action="{{ route('admin.days.notify.send', $day) }}" method="POST"
      onsubmit="return confirm('Envoyer le récapitulatif aux pilotes sélectionnés ?')">
    @csrf
    <table class="table table-striped align-middle">
        <thead>
            <tr>
                <th style="width:3rem" class="text-center">
                    <input type="checkbox" class="form-check-input" id="checkAll" checked>
                </th>
                <th>Pilote</th>
                <th>Adresse</th>
                <th class="text-center">Balises</th>
                <th class="text-end">Total</th>
                <th class="text-center">Statut</th>
            </tr>
        </thead>
        <tbody>
            @forelse($recaps as $recap)
                @php($pilot = $recap['pilot'])
                <tr class="{{ $pilot->email ? '' : 'table-warning' }}">
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input" name="pilots[]"
                               value="{{ $pilot->id }}" @checked($pilot->email) @disabled(!$pilot->email)>
                    </td>
                    <td>
                        {{ $pilot->name }}
                        @if($pilot->callsign)
                            <span class="text-muted">({{ $pilot->callsign }})</span>
                        @endif
                    </td>
                    <td>
                        @if($pilot->email)
                            <span class="font-monospace small">{{ $pilot->email }}</span>
                        @else
                            <span class="text-danger small">aucune adresse —
                                <a href="{{ route('admin.pilots.edit', $pilot) }}">renseigner</a>
                            </span>
                        @endif
                    </td>
                    <td class="text-center">{{ count($recap['turnpoints']) }}</td>
                    <td class="text-end fw-semibold">{{ $recap['total'] }}</td>
                    <td class="text-center">
                        @if($recap['validated'])
                            <span class="badge bg-success">Validé</span>
                        @else
                            <span class="badge bg-secondary">Provisoire</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">Aucun pilote dans cette compétition.</td></tr>
            @endforelse
        </tbody>
    </table>

    <button type="submit" class="btn btn-primary">Envoyer les récapitulatifs</button>
</form>

<script>
    document.getElementById('checkAll')?.addEventListener('change', function () {
        document.querySelectorAll('input[name="pilots[]"]:not(:disabled)')
            .forEach(cb => cb.checked = this.checked);
    });
</script>
@endsection
