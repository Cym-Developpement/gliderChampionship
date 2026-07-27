@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Planeurs — Jour {{ $day->day_number }} ({{ $day->date->format('d/m/Y') }})</h2>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.days.scores', $day) }}" class="btn btn-outline-primary">Scores</a>
        <a href="{{ route('admin.competition.edit') }}" class="btn btn-secondary">Retour</a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<p class="text-muted">
    Le planeur choisi ici ne vaut que pour cette journée : c'est son handicap qui entre
    dans le calcul des points, et son immatriculation qui rattache les positions OGN au pilote.
    Sans choix explicite, le planeur habituel du pilote est utilisé.
</p>

<form action="{{ route('admin.days.updateAssignments', $day) }}" method="POST">
    @csrf
    <table class="table table-striped align-middle">
        <thead>
            <tr>
                <th>Pilote</th>
                <th>Planeur du jour</th>
                <th>Handicap</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pilots as $pilot)
                @php($current = $selected[$pilot->id] ?? null)
                <tr>
                    <td>
                        {{ $pilot->name }}
                        @if($pilot->callsign)
                            <span class="text-muted">({{ $pilot->callsign }})</span>
                        @endif
                    </td>
                    <td style="min-width:22rem">
                        <select name="assignments[{{ $pilot->id }}]" class="form-select form-select-sm"
                                data-handicaps-target="handicap-{{ $pilot->id }}">
                            <option value="">— aucun planeur —</option>
                            @foreach($gliders as $glider)
                                <option value="{{ $glider->id }}"
                                        data-handicap="{{ number_format((float) $glider->handicap, 2) }}"
                                        @selected($current === $glider->id)>
                                    {{ $glider->reg }} — {{ trim($glider->glider_brand . ' ' . $glider->glider_model) }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td class="font-monospace" id="handicap-{{ $pilot->id }}">
                        {{ $current ? number_format((float) $gliders->firstWhere('id', $current)?->handicap, 2) : '—' }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-muted">Aucun pilote dans cette compétition.</td></tr>
            @endforelse
        </tbody>
    </table>

    <button type="submit" class="btn btn-primary">Enregistrer les affectations</button>
</form>

<script>
    // Reflète le handicap du planeur choisi, sans attendre l'enregistrement.
    document.querySelectorAll('select[data-handicaps-target]').forEach(function (select) {
        select.addEventListener('change', function () {
            const cell = document.getElementById(select.dataset.handicapsTarget);
            const option = select.options[select.selectedIndex];
            cell.textContent = option.dataset.handicap || '—';
        });
    });
</script>
@endsection
