@extends('admin.layout')

@section('admin')
    <h1 class="mb-1 text-2xl font-extrabold">Audit log</h1>
    <p class="mb-4 text-stone-600">Every change made in the admin panel: who, what, on which record and from where. Field values are not stored.</p>

    <form method="GET" class="mb-4 flex gap-2"><input name="q" value="{{ $term }}" class="input max-w-xs" placeholder="Search action or record" aria-label="Search"><button class="btn btn-outline btn-sm">Search</button></form>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">When</th><th class="th">Who</th><th class="th">Action</th><th class="th">Record</th><th class="th">Fields</th><th class="th">IP</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="td whitespace-nowrap">{{ $log->created_at->format('j M Y g:ia') }}</td>
                        <td class="td">{{ $log->user?->name ?? '?' }}</td>
                        <td class="td">{{ $log->action }} @if (($log->details['status'] ?? 200) >= 400) <span class="badge bg-red-50 text-red-800">failed</span> @endif</td>
                        <td class="td">{{ $log->subject }}</td>
                        <td class="td text-xs">{{ implode(', ', $log->details['fields'] ?? []) }}</td>
                        <td class="td">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <tr><td class="td" colspan="6">Nothing yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
