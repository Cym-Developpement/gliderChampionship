@extends('admin.layout')

@section('content')
<h2 class="mb-3">Modifier le pilote</h2>

<form action="{{ route('admin.pilots.update', $pilot) }}" method="POST" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label for="name" class="form-label">Nom</label>
                <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $pilot->name) }}" required>
            </div>
            <div class="mb-3">
                <label for="callsign" class="form-label">Callsign</label>
                <input type="text" name="callsign" id="callsign" class="form-control" value="{{ old('callsign', $pilot->callsign) }}">
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">E-mail</label>
                <input type="email" name="email" id="email" class="form-control" value="{{ old('email', $pilot->email) }}">
                <div class="form-text">Sert à l'envoi du récapitulatif de journée.</div>
            </div>
            <div class="mb-3">
                <label for="competition_id" class="form-label">Compétition</label>
                <select name="competition_id" id="competition_id" class="form-select" required>
                    @foreach($competitions as $competition)
                        <option value="{{ $competition->id }}" {{ old('competition_id', $pilot->competition_id) == $competition->id ? 'selected' : '' }}>{{ $competition->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label for="photo" class="form-label">Photo</label>
                <div class="mb-2">
                    <img src="{{ $pilot->photo_url ?: asset('pilote-picture.png') }}" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:50%">
                </div>
                <input type="file" name="photo" id="photo" class="form-control" accept="image/*">
            </div>
        </div>
    </div>

    <a href="{{ route('admin.pilots.index') }}" class="btn btn-secondary">Annuler</a>
    <button type="submit" class="btn btn-primary">Enregistrer</button>
</form>
@endsection
