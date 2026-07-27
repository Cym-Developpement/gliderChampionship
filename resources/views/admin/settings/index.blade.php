@extends('admin.layout')

@section('content')
<h2 class="mb-3">Paramètres</h2>

@php
    $knownSettings = [
        'proximity_far_m' => [
            'description' => 'Seuil de proximité lointain (en mètres)',
            'default' => '2000',
            'values' => 'Nombre entier positif',
        ],
        'proximity_near_m' => [
            'description' => 'Seuil de proximité proche (en mètres)',
            'default' => '1000',
            'values' => 'Nombre entier positif',
        ],
        'map_view_mode' => [
            'description' => 'Mode de vue carte',
            'default' => 'start_radius',
            'values' => 'start_radius | fit_participants',
        ],
        'map_radius_km' => [
            'description' => 'Rayon de la carte en km (mode start_radius)',
            'default' => '30',
            'values' => 'Nombre positif (km)',
        ],
        'map_zoom' => [
            'description' => 'Niveau de zoom fixe (mode start_radius, prioritaire sur le rayon)',
            'default' => 'auto',
            'values' => '1 (monde) à 18 (rue)',
        ],
        'scoring_formula' => [
            'description' => 'Formule de calcul des points',
            'default' => '(DISTANCE_TURNPOINT * BASE) / COEF_PLANEUR',
            'values' => 'Variables : DISTANCE_TURNPOINT, BASE, COEF_PLANEUR, HANDICAP',
        ],
        'scoring_base' => [
            'description' => 'Valeur de base par km',
            'default' => '100',
            'values' => 'Nombre positif',
        ],
        'turnpoint_unique_validation' => [
            'description' => 'Un turnpoint validé ne peut plus être revalidé les jours suivants',
            'default' => '0',
            'values' => '0 (revalidable chaque jour) | 1 (unique sur la compétition)',
        ],
    ];
    $existingKeys = $settings->pluck('key')->unique()->toArray();
@endphp

<div class="card mb-4">
    <div class="card-header">Ajouter un paramètre</div>
    <div class="card-body">
        <form action="{{ route('admin.settings.store') }}" method="POST" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3">
                <label class="form-label">Clé</label>
                <select name="key" class="form-select" required id="settingKeySelect">
                    <option value="">— Choisir —</option>
                    @foreach($knownSettings as $key => $meta)
                        <option value="{{ $key }}" {{ old('key') === $key ? 'selected' : '' }}
                            data-placeholder="{{ $meta['default'] }}"
                            data-description="{{ $meta['values'] }}">
                            {{ $key }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Valeur</label>
                <input type="text" name="value" class="form-control" value="{{ old('value') }}" required id="settingValueInput" title="">
            </div>
            <div class="col-md-3">
                <label class="form-label">Compétition</label>
                <select name="competition_id" class="form-select">
                    <option value="">Par défaut (toutes)</option>
                    @foreach($competitions as $competition)
                        <option value="{{ $competition->id }}" {{ old('competition_id') == $competition->id ? 'selected' : '' }}>{{ $competition->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">Ajouter</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Paramètres disponibles</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Clé</th>
                    <th>Description</th>
                    <th>Valeurs possibles</th>
                    <th>Défaut</th>
                </tr>
            </thead>
            <tbody>
                @foreach($knownSettings as $key => $meta)
                <tr>
                    <td><code>{{ $key }}</code></td>
                    <td>{{ $meta['description'] }}</td>
                    <td><code>{{ $meta['values'] }}</code></td>
                    <td><code>{{ $meta['default'] }}</code></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('settingKeySelect').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const input = document.getElementById('settingValueInput');
    input.placeholder = opt.dataset.placeholder || '';
    input.title = opt.dataset.description ? 'Valeurs : ' + opt.dataset.description : '';
});
</script>

<table class="table table-striped">
    <thead>
        <tr>
            <th>Clé</th>
            <th>Valeur</th>
            <th>Compétition</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse($settings as $setting)
        <tr>
            <td><code>{{ $setting->key }}</code></td>
            <td>
                <form action="{{ route('admin.settings.update', $setting) }}" method="POST" class="d-flex gap-2">
                    @csrf
                    @method('PUT')
                    <input type="text" name="value" class="form-control form-control-sm" value="{{ $setting->value }}" style="max-width: 300px;">
                    <button type="submit" class="btn btn-sm btn-outline-primary">OK</button>
                </form>
            </td>
            <td>
                @if($setting->competition)
                    <span class="badge bg-info">{{ $setting->competition->name }}</span>
                @else
                    <span class="text-muted">Par défaut</span>
                @endif
            </td>
            <td>
                <form action="{{ route('admin.settings.destroy', $setting) }}" method="POST" class="d-inline" onsubmit="return confirm('Supprimer ce paramètre ?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="4" class="text-muted">Aucun paramètre.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
