@extends('admin.layout')

@section('content')
<h2 class="mb-4">Dashboard</h2>

<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-primary h-100">
            <div class="card-body">
                <h5 class="card-title">Pilotes</h5>
                <p class="card-text display-6">{{ $pilotCount }}</p>
                <a href="{{ route('admin.pilots.index') }}" class="stretched-link"></a>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-info h-100">
            <div class="card-body">
                <h5 class="card-title">Participants</h5>
                <p class="card-text display-6">{{ $participantCount }}</p>
                <a href="{{ route('admin.participants.index') }}" class="stretched-link"></a>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-warning h-100">
            <div class="card-body">
                <h5 class="card-title">Turnpoints</h5>
                <p class="card-text display-6">{{ $turnpointCount }}</p>
                <a href="{{ route('admin.turnpoints.index') }}" class="stretched-link"></a>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-secondary h-100">
            <div class="card-body">
                <h5 class="card-title">Compétition</h5>
                <p class="card-text">
                    @if($comp)
                        @if($comp->status === 'active') <span class="badge bg-success">Active</span>
                        @elseif($comp->status === 'closed') <span class="badge bg-danger">Clôturée</span>
                        @else <span class="badge bg-secondary">En attente</span>
                        @endif
                    @else
                        <span class="text-light">–</span>
                    @endif
                </p>
                <a href="{{ route('admin.competition.edit') }}" class="stretched-link"></a>
            </div>
        </div>
    </div>
</div>

{{-- ── Journées ── --}}
@if($comp && $days->count() > 0)
<div class="card" id="journees">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Journées de vol</span>
        @if($comp->status === 'active')
        <form action="{{ route('admin.days.store') }}" method="POST" class="m-0">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">+ Nouvelle journée</button>
        </form>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Jour</th>
                    <th>Date</th>
                    <th>Statut</th>
                    <th>Démarré</th>
                    <th>Clôturé</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($days as $day)
                <tr @if($day->status === 'active') class="table-success" @endif>
                    <td class="fw-semibold">Jour {{ $day->day_number }}</td>
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
                    <td class="text-muted small">{{ $day->started_at?->format('H:i') ?? '–' }}</td>
                    <td class="text-muted small">{{ $day->closed_at?->format('H:i') ?? '–' }}</td>
                    <td>
                        <div class="d-flex gap-1 flex-wrap">
                            @if($day->status === 'pending')
                                <form action="{{ route('admin.days.start', $day) }}" method="POST" class="m-0">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm">Démarrer</button>
                                </form>
                            @elseif($day->status === 'active')
                                <form action="{{ route('admin.days.close', $day) }}" method="POST" class="m-0"
                                      onsubmit="return confirm('Clôturer ce jour ? Les scores seront figés.')">
                                    @csrf
                                    <button type="submit" class="btn btn-warning btn-sm">Clôturer</button>
                                </form>
                            @endif
                            <a href="{{ route('admin.days.scores', $day) }}"
                               class="btn btn-outline-primary btn-sm">Scores</a>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@elseif($comp)
<div class="card">
    <div class="card-body text-muted d-flex justify-content-between align-items-center">
        <span>Aucune journée créée.</span>
        @if($comp->status === 'active')
        <form action="{{ route('admin.days.store') }}" method="POST" class="m-0">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">+ Nouvelle journée</button>
        </form>
        @endif
    </div>
</div>
@else
<div class="alert alert-warning">
    Aucune compétition configurée. <a href="{{ route('admin.competition.edit') }}">Créer une compétition</a>.
</div>
@endif
@endsection
