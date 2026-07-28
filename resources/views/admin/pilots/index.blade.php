@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Pilotes</h2>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importCsv">
            Importer un CSV
        </button>
        <a href="{{ route('admin.pilots.create') }}" class="btn btn-primary">Ajouter un pilote</a>
    </div>
</div>

<div class="modal fade" id="importCsv" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="{{ route('admin.pilots.import') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Importer des pilotes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Compétition</label>
                    <select name="competition_id" class="form-select" required>
                        @foreach($competitions as $competition)
                            <option value="{{ $competition->id }}" @selected($loop->last)>{{ $competition->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Fichier CSV</label>
                    <input type="file" name="csv" class="form-control" accept=".csv,text/csv,text/plain" required>
                </div>
                <div class="alert alert-light border small mb-0">
                    Séparateur <code>;</code>, première ligne d'en-tête contenant au moins
                    <code>Nom</code> et <code>Prénom</code> ; les autres colonnes sont ignorées.
                    Le nom est enregistré sous la forme « Prénom Nom ».
                    Un pilote déjà présent n'est pas dupliqué ; seule son adresse
                    e-mail est mise à jour depuis la colonne <code>Email</code>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary">Importer</button>
            </div>
        </form>
    </div>
</div>

<table class="table table-striped">
    <thead>
        <tr>
            <th>Photo</th>
            <th>Nom</th>
            <th>Callsign</th>
            <th>Compétition</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse($pilots as $pilot)
        <tr>
            <td>
                <img src="{{ $pilot->photo_url ?: asset('pilote-picture.png') }}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:50%">
            </td>
            <td>{{ $pilot->name }}</td>
            <td>{{ $pilot->callsign }}</td>
            <td>{{ $pilot->competition->name ?? '-' }}</td>
            <td>
                <a href="{{ route('admin.pilots.edit', $pilot) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
                <form action="{{ route('admin.pilots.destroy', $pilot) }}" method="POST" class="d-inline" onsubmit="return confirm('Supprimer ce pilote ?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-muted">Aucun pilote.</td></tr>
        @endforelse
    </tbody>
</table>

{{ $pilots->links() }}
@endsection
