@extends('admin.layout')

@section('content')
<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<style>
    #pickMap { z-index: 0; }
</style>

<h2 class="mb-3">Compétition</h2>

@if($competition)
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <strong>Statut :</strong>
                @if($competition->status === 'pending')
                    <span class="badge bg-secondary">En attente</span>
                @elseif($competition->status === 'active')
                    <span class="badge bg-success">Active</span>
                @elseif($competition->status === 'closed')
                    <span class="badge bg-danger">Clôturée</span>
                @endif
            </div>
            <div>
                @if($competition->status === 'pending')
                    <form action="{{ route('admin.competition.activate') }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-success btn-sm">Démarrer la compétition</button>
                    </form>
                @elseif($competition->status === 'active')
                    <form action="{{ route('admin.competition.close') }}" method="POST" class="d-inline" onsubmit="return confirm('Clôturer la compétition ? Cette action est définitive.')">
                        @csrf
                        <button type="submit" class="btn btn-danger btn-sm">Clôturer la compétition</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endif

<form action="{{ route('admin.competition.update') }}" method="POST">
    @csrf
    @method('PUT')
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label for="name" class="form-label">Nom</label>
                <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $competition->name ?? '') }}" required>
            </div>
            <div class="mb-3">
                <label for="start_name" class="form-label">Nom du départ</label>
                <input type="text" name="start_name" id="start_name" class="form-control" value="{{ old('start_name', $competition->start_name ?? '') }}" required>
            </div>
            <div class="mb-3">
                <label for="start_lat" class="form-label">Latitude départ</label>
                <input type="number" step="any" name="start_lat" id="start_lat" class="form-control" value="{{ old('start_lat', $competition->start_lat ?? '') }}" required>
            </div>
            <div class="mb-3">
                <label for="start_lng" class="form-label">Longitude départ</label>
                <input type="number" step="any" name="start_lng" id="start_lng" class="form-control" value="{{ old('start_lng', $competition->start_lng ?? '') }}" required>
            </div>
            <div class="mb-3">
                <label for="start_radius_km" class="form-label">Rayon de départ (km)</label>
                <input type="number" name="start_radius_km" id="start_radius_km" class="form-control" value="{{ old('start_radius_km', $competition->start_radius_km ?? '') }}" required>
            </div>
            <button type="button" class="btn btn-outline-secondary mb-3" data-bs-toggle="modal" data-bs-target="#mapModal">
                Choisir sur la carte
            </button>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Enregistrer</button>
</form>

@if($competition)
<hr>
<h3 class="mt-4 mb-3">Journées de compétition</h3>

@if($competition->status === 'active')
<form action="{{ route('admin.days.store') }}" method="POST" class="mb-3">
    @csrf
    <button type="submit" class="btn btn-primary">Nouvelle journée</button>
</form>
@endif

@if($days->count() > 0)
<table class="table table-striped">
    <thead>
        <tr>
            <th>Jour</th>
            <th>Date</th>
            <th>Statut</th>
            <th>Démarré à</th>
            <th>Clôturé à</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($days as $day)
        <tr>
            <td>Jour {{ $day->day_number }}</td>
            <td>{{ $day->date->format('d/m/Y') }}</td>
            <td>
                @if($day->status === 'pending')
                    <span class="badge bg-secondary">En attente</span>
                @elseif($day->status === 'active')
                    <span class="badge bg-success">Actif</span>
                @elseif($day->status === 'closed')
                    <span class="badge bg-danger">Clôturé</span>
                @endif
            </td>
            <td>{{ $day->started_at ? $day->started_at->format('H:i') : '-' }}</td>
            <td>{{ $day->closed_at ? $day->closed_at->format('H:i') : '-' }}</td>
            <td>
                @if($day->status === 'pending')
                    <form action="{{ route('admin.days.start', $day) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-success btn-sm">Démarrer</button>
                    </form>
                @elseif($day->status === 'active')
                    <form action="{{ route('admin.days.close', $day) }}" method="POST" class="d-inline" onsubmit="return confirm('Clôturer ce jour ? Les scores seront figés.')">
                        @csrf
                        <button type="submit" class="btn btn-warning btn-sm">Clôturer</button>
                    </form>
                @endif
                <a href="{{ route('admin.days.scores', $day) }}" class="btn btn-outline-primary btn-sm">Scores</a>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<p class="text-muted">Aucune journée créée.</p>
@endif
@endif

<!-- Modal carte -->
<div class="modal fade" id="mapModal" tabindex="-1" aria-labelledby="mapModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mapModalLabel">Choisir le point de départ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body p-0">
                <div id="pickMap" style="height: 450px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" id="validateMap">Valider</button>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let map = null;
    let marker = null;
    let circle = null;
    const modalEl = document.getElementById('mapModal');

    function getRadiusMeters() {
        const km = parseFloat(document.getElementById('start_radius_km').value);
        return isNaN(km) || km <= 0 ? 5000 : km * 1000;
    }

    function placeMarker(lat, lng) {
        if (marker) {
            marker.setLatLng([lat, lng]);
            circle.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            circle = L.circle([lat, lng], { radius: getRadiusMeters(), color: '#3388ff', fillOpacity: 0.15 }).addTo(map);
            marker.on('dragend', function() {
                const pos = marker.getLatLng();
                circle.setLatLng(pos);
            });
        }
        circle.setRadius(getRadiusMeters());
    }

    modalEl.addEventListener('shown.bs.modal', function() {
        if (map) {
            map.invalidateSize();
            return;
        }

        const lat = parseFloat(document.getElementById('start_lat').value);
        const lng = parseFloat(document.getElementById('start_lng').value);
        const hasCoords = !isNaN(lat) && !isNaN(lng);
        const center = hasCoords ? [lat, lng] : [46.5, 2.5];
        const zoom = hasCoords ? 12 : 6;

        map = L.map('pickMap').setView(center, zoom);
        L.tileLayer('/tiles/osm/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        if (hasCoords) {
            placeMarker(lat, lng);
        }

        map.on('click', function(e) {
            placeMarker(e.latlng.lat, e.latlng.lng);
        });
    });

    document.getElementById('start_radius_km').addEventListener('input', function() {
        if (circle) {
            circle.setRadius(getRadiusMeters());
        }
    });

    document.getElementById('validateMap').addEventListener('click', function() {
        if (marker) {
            const pos = marker.getLatLng();
            document.getElementById('start_lat').value = Math.round(pos.lat * 1e6) / 1e6;
            document.getElementById('start_lng').value = Math.round(pos.lng * 1e6) / 1e6;
        }
        bootstrap.Modal.getInstance(modalEl).hide();
    });
});
</script>
@endsection
