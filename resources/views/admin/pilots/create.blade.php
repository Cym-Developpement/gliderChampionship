@extends('admin.layout')

@section('content')
<h2 class="mb-3">Ajouter un pilote</h2>

<form action="{{ route('admin.pilots.store') }}" method="POST" enctype="multipart/form-data">
    @csrf
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label for="name" class="form-label">Nom</label>
                <input type="text" name="name" id="name" class="form-control" value="{{ old('name') }}" required>
            </div>
            <div class="mb-3">
                <label for="callsign" class="form-label">Callsign</label>
                <input type="text" name="callsign" id="callsign" class="form-control" value="{{ old('callsign') }}">
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
                <label for="photo" class="form-label">Photo</label>
                <input type="file" name="photo" id="photo" class="form-control" accept="image/*">
            </div>
        </div>
    </div>

    <a href="{{ route('admin.pilots.index') }}" class="btn btn-secondary">Annuler</a>
    <button type="submit" class="btn btn-primary">Créer</button>
</form>
@endsection
