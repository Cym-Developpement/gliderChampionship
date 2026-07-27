@extends('admin.layout')

@section('content')
<h2 class="mb-3">Ajouter un participant</h2>

<form action="{{ route('admin.participants.store') }}" method="POST">
    @csrf
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label for="external_id" class="form-label">ID externe</label>
                <input type="text" name="external_id" id="external_id" class="form-control" value="{{ old('external_id') }}" required>
            </div>
            <div class="mb-3">
                <label for="name" class="form-label">Nom</label>
                <input type="text" name="name" id="name" class="form-control" value="{{ old('name') }}" required>
            </div>
            <div class="mb-3">
                <label for="ogn_id" class="form-label">OGN ID</label>
                <input type="text" name="ogn_id" id="ogn_id" class="form-control" value="{{ old('ogn_id') }}">
            </div>
            <div class="mb-3">
                <label for="reg" class="form-label">Immatriculation</label>
                <input type="text" name="reg" id="reg" class="form-control" value="{{ old('reg') }}">
            </div>
            <div class="mb-3">
                <label for="glider_brand" class="form-label">Marque planeur</label>
                <input type="text" name="glider_brand" id="glider_brand" class="form-control" value="{{ old('glider_brand') }}">
            </div>
            <div class="mb-3">
                <label for="glider_model" class="form-label">Modèle planeur</label>
                <input type="text" name="glider_model" id="glider_model" class="form-control" value="{{ old('glider_model') }}">
            </div>
            <div class="mb-3">
                <label for="handicap" class="form-label">Handicap</label>
                <input type="number" step="0.01" min="0" max="99.99" name="handicap" id="handicap" class="form-control" value="{{ old('handicap', '1.00') }}">
            </div>
            <div class="mb-3">
                <label for="competition_id" class="form-label">Compétition</label>
                <select name="competition_id" id="competition_id" class="form-select" required>
                    @foreach($competitions as $competition)
                        <option value="{{ $competition->id }}" {{ old('competition_id') == $competition->id ? 'selected' : '' }}>{{ $competition->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Pilotes associés</label>
                @foreach($pilots as $pilot)
                    <div class="form-check">
                        <input type="checkbox" name="pilots[]" value="{{ $pilot->id }}" id="pilot_{{ $pilot->id }}" class="form-check-input"
                            {{ in_array($pilot->id, old('pilots', [])) ? 'checked' : '' }}>
                        <label for="pilot_{{ $pilot->id }}" class="form-check-label">{{ $pilot->name }} ({{ $pilot->callsign }})</label>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <a href="{{ route('admin.participants.index') }}" class="btn btn-secondary">Annuler</a>
    <button type="submit" class="btn btn-primary">Créer</button>
</form>
@endsection
