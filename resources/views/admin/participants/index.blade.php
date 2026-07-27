@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Participants</h2>
    <a href="{{ route('admin.participants.create') }}" class="btn btn-primary">Ajouter un participant</a>
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
