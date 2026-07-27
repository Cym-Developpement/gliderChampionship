@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Turnpoints</h2>
    <div>
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#cupImport">Importer CUP</button>
        <a href="{{ route('admin.turnpoints.create') }}" class="btn btn-primary">Ajouter un turnpoint</a>
    </div>
</div>

<div class="collapse mb-3" id="cupImport">
    <div class="card card-body">
        <form action="{{ route('admin.turnpoints.import-cup') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="row align-items-end">
                <div class="col-md-4">
                    <label for="cup_file" class="form-label">Fichier CUP</label>
                    <input type="file" name="cup_file" id="cup_file" class="form-control" accept=".cup,.txt,.csv" required>
                </div>
                <div class="col-md-4">
                    <label for="import_competition_id" class="form-label">Compétition</label>
                    <select name="competition_id" id="import_competition_id" class="form-select" required>
                        @foreach(\App\Models\Competition::all() as $comp)
                            <option value="{{ $comp->id }}">{{ $comp->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-outline-primary">Importer</button>
                </div>
            </div>
        </form>
    </div>
</div>

<table class="table table-striped">
    <thead>
        <tr>
            <th>Ordre</th>
            <th>Nom</th>
            <th>Lat</th>
            <th>Lng</th>
            <th>Rayon (m)</th>
            <th>Points</th>
            <th>Compétition</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse($turnpoints as $tp)
        <tr>
            <td>{{ $tp->order }}</td>
            <td>{{ $tp->name }}</td>
            <td>{{ $tp->lat }}</td>
            <td>{{ $tp->lng }}</td>
            <td>{{ $tp->radius_m }}</td>
            <td>{{ $tp->points }}</td>
            <td>{{ $tp->competition->name ?? '-' }}</td>
            <td>
                <a href="{{ route('admin.turnpoints.edit', $tp) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
                <form action="{{ route('admin.turnpoints.destroy', $tp) }}" method="POST" class="d-inline" onsubmit="return confirm('Supprimer ce turnpoint ?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" class="text-muted">Aucun turnpoint.</td></tr>
        @endforelse
    </tbody>
</table>

{{ $turnpoints->links() }}
@endsection
