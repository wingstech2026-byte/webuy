@extends('admin.layout')

@section('admin')
    <h1 class="mb-1 text-2xl font-extrabold">AI shopping assistant</h1>
    <p class="mb-4 text-stone-600">
        {{ $enabled ? 'Switched on.' : 'Switched off: set ASSISTANT_ENABLED=true and ANTHROPIC_API_KEY in .env.' }}
        {{ $today }} request(s) today. This month: {{ number_format($tokens['input']) }} input and {{ number_format($tokens['output']) }} output tokens.
        Daily limit per person is in <a class="font-semibold text-brand-700" href="{{ route('admin.rewards.settings') }}">Settings and limits</a>.
    </p>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">When</th><th class="th">Who</th><th class="th">Asked</th><th class="th">Understood</th><th class="th text-right">Tokens</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="td whitespace-nowrap">{{ $log->created_at->format('j M g:ia') }}</td>
                        <td class="td">{{ $log->user->name }}</td>
                        <td class="td">{{ $log->message }}</td>
                        <td class="td text-xs">@if ($log->status === 'error') <span class="text-red-700">{{ $log->understood['error'] ?? 'error' }}</span> @else {{ collect($log->understood)->except('reply')->filter()->map(fn ($v, $k) => "$k: $v")->implode(', ') }} @endif</td>
                        <td class="td text-right">{{ $log->input_tokens + $log->output_tokens }}</td>
                    </tr>
                @empty
                    <tr><td class="td" colspan="5">No requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
