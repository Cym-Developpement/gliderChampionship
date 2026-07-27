@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Pilotes</h2>
    <a href="{{ route('admin.pilots.create') }}" class="btn btn-primary">Ajouter un pilote</a>
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
