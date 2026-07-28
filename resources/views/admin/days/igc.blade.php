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
                    <th class="text-center" title="Heure de validation en vol">Heure</th>
                </tr>
            </thead>
            <tbody>
                @foreach($turnpoints as $i => $tp)
                <tr>
                    <td class="text-muted">{{ $i + 1 }}</td>
                    <td>{{ $tp->name }}</td>
                    <td>{{ $tp->points }}</td>
                    <td>{{ number_format($tp->validationRadiusM()) }} m</td>
                    <td class="text-center">
                        @if(isset($flarmIds[$tp->id]))
                            <span class="badge bg-success">✓ Validé</span>
                        @else
                            <span class="text-muted">–</span>
                        @endif
                    </td>
                    <td class="text-center font-monospace small">
                        @if(!empty($flarmTimes[$tp->id]))
                            {{ \Carbon\Carbon::parse($flarmTimes[$tp->id])->format('H:i:s') }}
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

        @isset($altitudes)
            <div class="card-body border-bottom">
                <div class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <div class="text-muted small">Altitude du terrain</div>
                        <div class="fs-5">{{ $altitudes['ground'] !== null ? number_format($altitudes['ground']) . ' m' : '—' }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">
                            Hauteur la plus basse
                            <span title="Décollage et atterrissage exclus">hors décollage et atterrissage</span>
                        </div>
                        <div class="fs-5 {{ $altitudes['min_height'] !== null && $altitudes['min_height'] <= $altitudes['threshold'] ? 'text-danger fw-bold' : '' }}">
                            {{ $altitudes['min_height'] !== null ? number_format($altitudes['min_height']) . ' m' : '—' }}
                            @if($altitudes['min_at'])
                                <span class="text-muted small">à {{ \Carbon\Carbon::parse($altitudes['min_at'])->format('H:i:s') }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-5">
                        @if($altitudes['vache_at'])
                            <div class="alert alert-danger mb-0 py-2">
                                <strong>Vache présumée</strong> à
                                {{ \Carbon\Carbon::parse($altitudes['vache_at'])->format('H:i:s') }} —
                                passage sous {{ number_format($altitudes['threshold']) }} m.
                                Les balises franchies ensuite ne sont pas comptées.
                            </div>
                        @else
                            <div class="alert alert-success mb-0 py-2">
                                Jamais descendu sous {{ number_format($altitudes['threshold']) }} m en vol.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endisset

        <div class="card-body p-0">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:2rem">#</th>
                        <th>Point de virage</th>
                        <th class="text-end" title="Points rapportés, formule appliquée">Points</th>
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
                        // Validée par l'IGC, sauf si franchie après la vache —
                        // le vol était alors terminé — ou déjà acquise un
                        // autre jour, le règlement interdisant de la recompter.
                        $afterVache    = $r['after_vache'] ?? false;
                        $alreadyDay    = $r['already_day'] ?? null;
                        $shouldInclude = $igcOk && !$afterVache && !$alreadyDay;
                        $rowClass = match(true) {
                            $igcOk && $flarmOk  => '',
                            $igcOk && !$flarmOk => 'table-success',
                            !$igcOk && $flarmOk => 'table-warning',
                            default             => '',
                        };
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td class="text-muted">{{ $i + 1 }}</td>
                        <td class="fw-semibold">
                            {{ $tp->name }}
                            @if($afterVache)
                                <span class="badge bg-danger ms-1" title="Franchie après la vache">après vache</span>
                            @endif
                            @if($alreadyDay)
                                <span class="badge bg-secondary ms-1"
                                      title="Déjà validée au jour {{ $alreadyDay }} — le règlement interdit de la compter deux fois">
                                    déjà acquise J{{ $alreadyDay }}
                                </span>
                            @endif
                        </td>
                        {{-- Une balise déjà acquise ne rapporte rien : sa valeur
                             est barrée et exclue du total. --}}
                        <td class="text-end fw-semibold" data-points="{{ $alreadyDay ? 0 : $r['points'] }}">
                            @if($alreadyDay)
                                <span class="text-decoration-line-through text-muted">{{ $r['points'] }}</span>
                                <span class="d-block text-muted small">0 pt</span>
                            @else
                                {{ $r['points'] }}
                                @if($tp->points)
                                    <span class="text-muted small d-block">balise {{ $tp->points }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="text-end text-muted">{{ number_format($tp->validationRadiusM()) }} m</td>

                        {{-- FLARM status --}}
                        <td class="text-center">
                            @if($flarmOk)
                                <span class="badge bg-success">✓</span>
                                @if(!empty($flarmTimes[$tp->id]))
                                    <span class="d-block text-muted font-monospace" style="font-size:.75rem">
                                        {{ \Carbon\Carbon::parse($flarmTimes[$tp->id])->format('H:i:s') }}
                                    </span>
                                @endif
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
                                    <span class="text-muted small">(rayon {{ number_format($tp->validationRadiusM()) }} m)</span>
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
                            @if($alreadyDay)
                                {{-- Verrouillée : la recompter contredirait le
                                     règlement de validation unique. --}}
                                <span class="text-muted small">acquise</span>
                            @elseif($igcOk)
                                <input type="hidden" name="igc_turnpoints[{{ $tp->id }}][distance_m]" value="{{ $distM }}">
                                <div class="form-check d-flex justify-content-center mb-0">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="igc_turnpoints[{{ $tp->id }}][include]"
                                           value="1"
                                           {{ $shouldInclude ? 'checked' : '' }}>
                                </div>
                            @elseif($flarmOk)
                                {{-- Validée en vol mais absente de la trace : décochée par défaut,
                                     l'organisateur reste libre de la maintenir. --}}
                                <div class="form-check d-flex justify-content-center mb-0">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="igc_turnpoints[{{ $tp->id }}][include]"
                                           value="1"
                                           title="Validée par FLARM mais non confirmée par la trace">
                                </div>
                            @else
                                @if($distM !== null)
                                    <span class="text-muted small">hors zone</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="2" class="text-end">Balises retenues</th>
                        <th class="text-end" id="basePoints">0</th>
                        <th colspan="6"></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="card-body border-top">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="vache" id="vache" value="1"
                               @checked(isset($altitudes) && $altitudes['vache_at'])>
                        <label class="form-check-label" for="vache">
                            <strong>Vaché</strong> — diviser les points par 2
                        </label>
                    </div>
                    @if(isset($altitudes) && $altitudes['vache_at'])
                        <div class="form-text text-danger">Coché d'après l'analyse du fichier.</div>
                    @endif
                </div>

                <div class="col-md-3">
                    <label for="bonus_points" class="form-label">Points bonus</label>
                    <input type="number" class="form-control" name="bonus_points" id="bonus_points"
                           value="0" min="0" step="1">
                    <div class="form-text">Ajoutés après la division.</div>
                </div>

                <div class="col-md-5 text-md-end">
                    <div class="text-muted small">Total attribué</div>
                    <div class="display-6" id="totalPoints">0</div>
                    <div class="text-muted small" id="totalDetail"></div>
                    {{-- Le total affiché fait foi : c'est lui qui est enregistré,
                         sans recalcul côté serveur. --}}
                    <input type="hidden" name="total_points" id="totalPointsField" value="0">
                </div>
            </div>
        </div>

        <script>
            // Le total suit les cases cochées, la vache et le bonus : il annonce
            // ce qui sera réellement enregistré, pas ce que le fichier a détecté.
            (function () {
                const rows  = Array.from(document.querySelectorAll('tbody tr'));
                const base  = document.getElementById('basePoints');
                const total = document.getElementById('totalPoints');
                const detail = document.getElementById('totalDetail');
                const vache = document.getElementById('vache');
                const bonus = document.getElementById('bonus_points');
                if (!total) return;

                function refresh() {
                    let sum = 0;
                    for (const row of rows) {
                        const box  = row.querySelector('input[type="checkbox"]');
                        const cell = row.querySelector('[data-points]');
                        if (box && box.checked && cell) {
                            sum += parseInt(cell.dataset.points, 10) || 0;
                        }
                    }

                    const extra   = Math.max(0, parseInt(bonus.value, 10) || 0);
                    const divided = vache.checked ? Math.round(sum / 2) : sum;

                    base.textContent  = sum;
                    total.textContent = divided + extra;
                    document.getElementById('totalPointsField').value = divided + extra;

                    const parts = [sum + ' pts'];
                    if (vache.checked) parts.push('÷ 2 (vaché)');
                    if (extra > 0)     parts.push('+ ' + extra + ' bonus');
                    detail.textContent = parts.join('  ');
                }

                rows.forEach(row => {
                    const box = row.querySelector('input[type="checkbox"]');
                    if (box) box.addEventListener('change', refresh);
                });
                vache.addEventListener('change', refresh);
                bonus.addEventListener('input', refresh);
                refresh();
            })();
        </script>

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
