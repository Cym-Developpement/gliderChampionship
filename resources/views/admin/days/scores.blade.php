@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Scores — Jour {{ $day->day_number }} ({{ $day->date->format('d/m/Y') }})</h2>
    <a href="{{ route('admin.competition.edit') }}" class="btn btn-secondary">Retour</a>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<form action="{{ route('admin.days.updateScores', $day) }}" method="POST">
    @csrf
    <table class="table table-striped align-middle">
        <thead>
            <tr>
                <th>Pilote</th>
                <th>Points auto</th>
                <th>Handicap</th>
                <th>Score final</th>
                <th title="Cocher si les scores ont été validés via fichier IGC">
                    Validé (IGC) <span class="text-muted" style="font-size:0.75rem;">✓</span>
                </th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach($scores as $i => $entry)
            <tr>
                <td>
                    {{ $entry['pilot']->name }}
                    @if($entry['pilot']->callsign)
                        <small class="text-muted ms-1">{{ $entry['pilot']->callsign }}</small>
                    @endif
                    <input type="hidden" name="scores[{{ $i }}][pilot_id]" value="{{ $entry['pilot']->id }}">
                </td>
                <td>{{ $entry['raw_points'] }}</td>
                <td>{{ number_format($entry['handicap'], 2) }}</td>
                <td>
                    <input type="number" name="scores[{{ $i }}][points]" class="form-control form-control-sm" style="width: 120px;" value="{{ $entry['final_points'] }}" min="0">
                </td>
                <td class="text-center">
                    <div class="form-check d-flex justify-content-center">
                        <input class="form-check-input" type="checkbox"
                               name="scores[{{ $i }}][is_validated]"
                               value="1"
                               {{ $entry['is_validated'] ? 'checked' : '' }}>
                    </div>
                </td>
                <td>
                    <a href="{{ route('admin.igc.show', [$day, $entry['pilot']]) }}"
                       class="btn btn-outline-primary btn-sm"
                       title="Valider via fichier IGC">IGC</a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="d-flex align-items-center gap-3">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <button type="button" class="btn btn-outline-success btn-sm" id="validateAll">Tout valider</button>
        <span class="text-muted small">Cochez "Validé" après vérification des fichiers IGC pour retirer le badge <span class="badge bg-danger" style="font-size:0.65rem;">PROVISOIRE</span>.</span>
    </div>
</form>

<script>
document.getElementById('validateAll').addEventListener('click', function() {
    document.querySelectorAll('input[type="checkbox"][name$="[is_validated]"]').forEach(cb => cb.checked = true);
});
</script>
@endsection
