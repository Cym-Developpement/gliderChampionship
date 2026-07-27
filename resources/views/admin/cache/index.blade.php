@extends('admin.layout')

@section('content')
<h2 class="mb-4">Caches</h2>

<div class="card mb-4">
    <div class="card-header fw-semibold">État actuel</div>
    <ul class="list-group list-group-flush">
        @foreach($status as $item)
            <li class="list-group-item">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-medium">{{ $item['label'] }}</span>
                    <span class="badge bg-{{ $item['active'] ? 'warning text-dark' : 'success' }}">
                        {{ $item['active'] ? 'actif' : 'vide' }}
                    </span>
                </div>
                <div class="text-muted small mt-1">{{ $item['detail'] }}</div>
            </li>
        @endforeach
    </ul>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">Vider les caches</h5>
                <p class="card-text text-muted small">
                    Vues compilées, routes, configuration et cache applicatif.
                    À faire après chaque mise à jour des fichiers par FTP : sans cela,
                    les nouvelles routes répondent 404 et les vues gardent leur ancien contenu.
                </p>
                <form action="{{ route('admin.cache.clear') }}" method="POST">
                    @csrf
                    <button class="btn btn-primary">Vider les caches</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">Regénérer les caches</h5>
                <p class="card-text text-muted small">
                    Recompile les vues et les routes pour accélérer l'affichage.
                    Facultatif : sans cache, l'application fonctionne, un peu plus lentement.
                    Le cache de configuration reste volontairement exclu.
                </p>
                <form action="{{ route('admin.cache.rebuild') }}" method="POST">
                    @csrf
                    <button class="btn btn-outline-secondary">Regénérer</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
