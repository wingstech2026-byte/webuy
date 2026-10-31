@if ($campaign->applications->isNotEmpty())
    <div class="mt-3 divide-y divide-stone-100 border-t border-stone-100">
        @foreach ($campaign->applications as $application)
            <div class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <div>
                    <strong>{{ $application->user->name ?? phone_display($application->user->phone) }}</strong>
                    <span class="badge bg-stone-100">{{ $application->status }}</span>
                    @if ($application->review_due_at && $application->status === 'selected') <span class="text-xs text-stone-500">review due {{ $application->review_due_at->format('j M') }}</span> @endif
                    @if ($application->reason) <p class="text-stone-600">{{ $application->reason }}</p> @endif
                </div>
                @if ($application->status === 'applied')
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route($selectRoute, $application) }}">@csrf<button class="btn btn-primary btn-sm">Select</button></form>
                        <form method="POST" action="{{ route($rejectRoute, $application) }}">@csrf<button class="btn btn-outline btn-sm">Decline</button></form>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@else
    <p class="mt-2 text-sm text-stone-500">No applications yet.</p>
@endif
