@extends('admin.layout')

@section('content')
<h2 class="mb-4">Migrations</h2>

@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

@if(session('output'))
    <pre class="bg-light border rounded p-3 small">{{ session('output') }}</pre>
@endif

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">En attente</span>
        <span class="badge bg-{{ $pending === [] ? 'success' : 'warning text-dark' }}">
            {{ count($pending) }}
        </span>
    </div>

    @if($pending === [])
        <div class="card-body text-muted">
            La base est à jour : toutes les migrations livrées ont été appliquées.
        </div>
    @else
        <ul class="list-group list-group-flush">
            @foreach($pending as $migration)
                <li class="list-group-item font-monospace small">{{ $migration }}</li>
            @endforeach
        </ul>
        <div class="card-body">
            <p class="text-muted small">
                Connexion <code>{{ $connection }}</code>.
                @if($backupPath)
                    Une copie de la base est déposée dans <code>{{ $backupPath }}</code> avant exécution.
                @else
                    La sauvegarde préalable n'est automatique qu'en SQLite : pensez à sauvegarder votre base avant.
                @endif
            </p>
            <form action="{{ route('admin.migrations.run') }}" method="POST"
                  onsubmit="return confirm('Appliquer {{ count($pending) }} migration(s) ? La structure de la base va être modifiée.')">
                @csrf
                <button class="btn btn-primary">Exécuter les migrations</button>
            </form>
        </div>
    @endif
</div>

<div class="card">
    <div class="card-header fw-semibold">Dernières migrations appliquées</div>
    @if($applied === [])
        <div class="card-body text-muted">Aucune migration enregistrée.</div>
    @else
        <ul class="list-group list-group-flush">
            @foreach($applied as $migration)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="font-monospace small">{{ $migration['name'] }}</span>
                    <span class="badge bg-secondary">lot {{ $migration['batch'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
