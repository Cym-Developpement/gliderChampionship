@extends('admin.layout')

@section('content')
<h2 class="mb-3">Modifier le turnpoint</h2>

<form action="{{ route('admin.turnpoints.update', $turnpoint) }}" method="POST">
    @csrf
    @method('PUT')
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label for="name" class="form-label">Nom</label>
                <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $turnpoint->name) }}" required>
            </div>
            <div class="mb-3">
                <label for="lat" class="form-label">Latitude</label>
                <input type="number" step="any" name="lat" id="lat" class="form-control" value="{{ old('lat', $turnpoint->lat) }}" required>
            </div>
            <div class="mb-3">
                <label for="lng" class="form-label">Longitude</label>
                <input type="number" step="any" name="lng" id="lng" class="form-control" value="{{ old('lng', $turnpoint->lng) }}" required>
            </div>
            <div class="mb-3">
                <label for="radius_m" class="form-label">Rayon (m)</label>
                <input type="number" name="radius_m" id="radius_m" class="form-control" value="{{ old('radius_m', $turnpoint->radius_m) }}" required>
            </div>
            <div class="mb-3">
                <label for="points" class="form-label">Points</label>
                <input type="number" name="points" id="points" class="form-control" value="{{ old('points', $turnpoint->points) }}" required>
            </div>
            <div class="mb-3">
                <label for="order" class="form-label">Ordre</label>
                <input type="number" name="order" id="order" class="form-control" value="{{ old('order', $turnpoint->order) }}" required>
            </div>
            <div class="mb-3">
                <label for="competition_id" class="form-label">Compétition</label>
                <select name="competition_id" id="competition_id" class="form-select" required>
                    @foreach($competitions as $competition)
                        <option value="{{ $competition->id }}" {{ old('competition_id', $turnpoint->competition_id) == $competition->id ? 'selected' : '' }}>{{ $competition->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <a href="{{ route('admin.turnpoints.index') }}" class="btn btn-secondary">Annuler</a>
    <button type="submit" class="btn btn-primary">Enregistrer</button>
</form>
@endsection
