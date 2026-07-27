@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="mb-0">Validation IGC — {{ $pilot->name }}
            @if($pilot->callsign)
                <small class="text-muted fs-5">{{ $pilot->callsign }}</small>
            @endif
        </h2>
        <div class="text-muted small">Jour {{ $day->day_number }} — {{ $day->date->format('d/m/Y') }}</div>
    </div>
    <a href="{{ route('admin.days.scores', $day) }}" class="btn btn-secondary">← Retour aux scores</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- ── Upload form ── --}}
@if(!isset($results))
<div class="card mb-4">
    <div class="card-header">Importer le fichier IGC</div>
    <div class="card-body">
        <form action="{{ route('admin.igc.process', [$day, $pilot]) }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label class="form-label fw-semibold">Fichier IGC du vol</label>
                <input type="file" name="igc_file" accept=".igc,.IGC" class="form-control" required>
                <div class="form-text">Fichier au format IGC (enregistrement FLARM ou autre logger GPS).</div>
            </div>
            <button type="submit" class="btn btn-primary">Analyser</button>
        </form>
    </div>
</div>

{{-- ── FLARM summary (before IGC upload) ── --}}
<div class="card">
    <div class="card-header">Points validés par FLARM</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Point de virage</th>
                    <th>Points</th>
                    <th>Rayon</th>
                    <th class="text-center">FLARM</th>
                </tr>
            </thead>
            <tbody>
                @foreach($turnpoints as $i => $tp)
                <tr>
                    <td class="text-muted">{{ $i + 1 }}</td>
                    <td>{{ $tp->name }}</td>
                    <td>{{ $tp->points }}</td>
                    <td>{{ $tp->radius_m }} m</td>
                    <td class="text-center">
                        @if(isset($flarmIds[$tp->id]))
                            <span class="badge bg-success">✓ Validé</span>
                        @else
                            <span class="text-muted">–</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@else
{{-- ── Results + save form ── --}}
<form action="{{ route('admin.igc.save', [$day, $pilot]) }}" method="POST">
    @csrf

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Récapitulatif IGC
                <span class="badge bg-secondary ms-1">{{ number_format($fixCount) }} pts GPS</span>
                @if(!is_null($maxSpeedKmh))
                    <span class="badge bg-info ms-1">{{ number_format($maxSpeedKmh, 0) }} km/h max</span>
                @endif
                @if(!is_null($maxAltM))
                    <span class="badge bg-primary ms-1">{{ number_format($maxAltM) }} m alt. max</span>
                @endif
            </span>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnNewFile">
                    ↑ Importer un autre fichier
                </button>
                <button type="submit" class="btn btn-success">
                    ✓ Valider et sauvegarder
                </button>
            </div>
        </div>

        <div class="card-body p-0">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:2rem">#</th>
                        <th>Point de virage</th>
                        <th class="text-end">Points</th>
                        <th class="text-end">Rayon</th>
                        <th class="text-center">FLARM</th>
                        <th class="text-center">IGC</th>
                        <th class="text-end">Distance IGC</th>
                        <th class="text-center" title="Heure d'entrée dans la zone">Heure</th>
                        <th class="text-center" title="Inclure dans la validation">Inclure</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($results as $i => $r)
                    @php
                        $tp         = $r['turnpoint'];
                        $flarmOk    = $r['flarm'];
                        $igcOk      = $r['igc'];
                        $distM      = $r['distance_m'];
                        // Include if IGC-validated (default on), or already FLARM (already in DB, no need to re-add)
                        $shouldInclude = $igcOk;
                        $rowClass = match(true) {
                            $igcOk && $flarmOk  => '',
                            $igcOk && !$flarmOk => 'table-success',
                            !$igcOk && $flarmOk => 'table-warning',
                            default             => '',
                        };
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td class="text-muted">{{ $i + 1 }}</td>
                        <td class="fw-semibold">{{ $tp->name }}</td>
                        <td class="text-end">{{ $tp->points }}</td>
                        <td class="text-end text-muted">{{ number_format($tp->radius_m) }} m</td>

                        {{-- FLARM status --}}
                        <td class="text-center">
                            @if($flarmOk)
                                <span class="badge bg-success">✓</span>
                            @else
                                <span class="text-muted">–</span>
                            @endif
                        </td>

                        {{-- IGC status --}}
                        <td class="text-center">
                            @if($igcOk)
                                <span class="badge bg-success">✓</span>
                            @elseif($distM !== null)
                                <span class="badge bg-danger">✗</span>
                            @else
                                <span class="text-muted">–</span>
                            @endif
                        </td>

                        {{-- Distance --}}
                        <td class="text-end font-monospace small">
                            @if($distM !== null)
                                @if($igcOk)
                                    <span class="text-success fw-bold">{{ number_format($distM) }} m</span>
                                @else
                                    <span class="text-danger">{{ number_format($distM) }} m</span>
                                    <span class="text-muted small">(rayon {{ number_format($tp->radius_m) }} m)</span>
                                @endif
                            @else
                                <span class="text-muted">–</span>
                            @endif
                        </td>

                        {{-- Heure d'entrée dans la zone --}}
                        <td class="text-center font-monospace small">
                            @if($r['validated_at'])
                                {{ \Carbon\Carbon::parse($r['validated_at'])->format('H:i:s') }}
                            @elseif($r['igc'])
                                <span class="text-muted">–</span>
                            @else
                                <span class="text-muted">–</span>
                            @endif
                        </td>

                        {{-- Include checkbox --}}
                        <td class="text-center">
                            @if($igcOk)
                                <input type="hidden" name="igc_turnpoints[{{ $tp->id }}][distance_m]" value="{{ $distM }}">
                                <div class="form-check d-flex justify-content-center mb-0">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="igc_turnpoints[{{ $tp->id }}][include]"
                                           value="1"
                                           {{ $shouldInclude ? 'checked' : '' }}>
                                </div>
                            @else
                                {{-- Not IGC-validated: hidden but carries distance for reference --}}
                                @if($distM !== null)
                                    <span class="text-muted small">hors zone</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="small text-muted">
                @php
                    $igcCount   = collect($results)->where('igc', true)->count();
                    $flarmCount = collect($results)->where('flarm', true)->count();
                    $newCount   = collect($results)->where('igc', true)->where('flarm', false)->count();
                    $missedCount = collect($results)->where('igc', false)->where('flarm', true)->count();
                @endphp
                IGC : <strong>{{ $igcCount }}</strong> point(s) validé(s) ·
                FLARM : <strong>{{ $flarmCount }}</strong> ·
                @if($newCount > 0)
                    <span class="text-success fw-semibold">+{{ $newCount }} nouveau(x) à ajouter</span> ·
                @endif
                @if($missedCount > 0)
                    <span class="text-warning fw-semibold">{{ $missedCount }} manquant(s) IGC</span>
                @endif
            </div>
            <button type="submit" class="btn btn-success">
                ✓ Valider et sauvegarder
            </button>
        </div>
    </div>

    {{-- Legend --}}
    <div class="d-flex gap-3 flex-wrap small text-muted mb-4">
        <span><span class="badge bg-success">✓</span> Validé</span>
        <span><span class="badge bg-danger">✗</span> Non validé</span>
        <span style="background:#d1e7dd;padding:2px 8px;border-radius:4px;">Ligne verte = nouveau point ajouté par IGC</span>
        <span style="background:#fff3cd;padding:2px 8px;border-radius:4px;">Ligne orange = validé FLARM mais pas IGC</span>
    </div>
</form>

{{-- New file upload (hidden by default) --}}
<div id="newFileForm" class="card" style="display:none">
    <div class="card-header">Importer un autre fichier IGC</div>
    <div class="card-body">
        <form action="{{ route('admin.igc.process', [$day, $pilot]) }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <input type="file" name="igc_file" accept=".igc,.IGC" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Analyser</button>
        </form>
    </div>
</div>

<script>
document.getElementById('btnNewFile')?.addEventListener('click', function() {
    const f = document.getElementById('newFileForm');
    f.style.display = f.style.display === 'none' ? '' : 'none';
});
</script>
@endif
@endsection
