@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>OGN Live — Devices non attribués</h2>
    <a href="{{ route('admin.ogn-assign.index') }}" class="btn btn-outline-secondary">Rafraîchir</a>
</div>

@if($error)
    <div class="alert alert-warning">{{ $error }}</div>
@elseif(empty($devices))
    <div class="alert alert-info">Aucun device OGN non attribué détecté.</div>
@else
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Reg</th>
                <th>OGN ID</th>
                <th>Nom</th>
                <th>Alt</th>
                <th>Vitesse sol</th>
                <th>Assigner</th>
            </tr>
        </thead>
        <tbody>
            @foreach($devices as $device)
                <tr>
                    <td>{{ $device['reg'] }}</td>
                    <td>{{ $device['id'] }}</td>
                    <td>{{ $device['name'] }}</td>
                    <td>{{ $device['alt_raw'] ?? '—' }}</td>
                    <td>{{ $device['ground_speed'] ?? '—' }}</td>
                    <td>
                        <form action="{{ route('admin.ogn-assign.store') }}" method="POST" class="d-flex gap-2 align-items-center">
                            @csrf
                            <input type="hidden" name="reg" value="{{ $device['reg'] }}">
                            <input type="hidden" name="ogn_id" value="{{ $device['id'] }}">
                            <select name="participant_id" class="form-select form-select-sm" style="min-width: 150px;" required>
                                <option value="">— Participant —</option>
                                @foreach($participants as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->reg }})</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm">Assigner</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@endsection
