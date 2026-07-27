@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Participants</h2>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importCsv">
            Importer un CSV
        </button>
        <a href="{{ route('admin.participants.create') }}" class="btn btn-primary">Ajouter un participant</a>
    </div>
</div>

<div class="modal fade" id="importCsv" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="{{ route('admin.participants.import') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Importer des planeurs</h5>
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
                    <code>Immatriculation</code> ; <code>Marque</code>, <code>Modèle</code> et
                    <code>Pilote propriétaire</code> sont repris s'ils sont présents.
                    Le planeur est rattaché au pilote de même nom déjà enregistré dans la compétition.
                    Les immatriculations déjà présentes sont laissées inchangées.
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
            <th>ID externe</th>
            <th>Nom</th>
            <th>OGN ID</th>
            <th>Reg</th>
            <th>Planeur</th>
            <th>Handicap</th>
            <th>Compétition</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse($participants as $participant)
        <tr>
            <td>{{ $participant->external_id }}</td>
            <td>{{ $participant->name }}</td>
            <td>{{ $participant->ogn_id ?? '-' }}</td>
            <td>{{ $participant->reg ?? '-' }}</td>
            <td>{{ $participant->glider_brand }} {{ $participant->glider_model }}</td>
            <td>{{ number_format($participant->handicap, 2) }}</td>
            <td>{{ $participant->competition->name ?? '-' }}</td>
            <td>
                <a href="{{ route('admin.participants.edit', $participant) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
                <form action="{{ route('admin.participants.destroy', $participant) }}" method="POST" class="d-inline" onsubmit="return confirm('Supprimer ce participant ?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" class="text-muted">Aucun participant.</td></tr>
        @endforelse
    </tbody>
</table>

{{ $participants->links() }}
@endsection
