{{-- The return on an order, for the buyer ($forSeller false) or the seller ($forSeller true). --}}
@php($return = $order->returnRequest)
<div class="card space-y-2 p-4">
    <p class="font-bold">↩️ Return: {{ $return->statusLabel() }}</p>
    <p class="text-sm">{{ $return->itemsLine() }} &middot; {{ \App\Models\ReturnRequest::REASONS[$return->reason] }}</p>
    <p class="text-sm text-stone-600">{{ $return->description }}</p>
    @if ($return->photos->isNotEmpty())
        <div class="flex gap-2">@foreach ($return->photos as $photo) @if ($photo->thumb_url) <a href="{{ $photo->url }}" target="_blank"><img src="{{ $photo->thumb_url }}" alt="Return photo" class="size-16 rounded-lg object-cover"></a> @endif @endforeach</div>
    @endif
    <p class="text-sm">{{ $forSeller ? 'Refund to the buyer if accepted' : 'Your refund' }}: <strong>{{ money($return->refund_amount) }}</strong>{{ $return->is_full ? ' (everything you paid)' : '' }}</p>

    @if ($return->return_instructions)
        <p class="rounded-lg bg-brand-50 p-2 text-sm"><strong>How to send it back:</strong> {{ $return->return_instructions }}</p>
    @endif
    @if ($return->seller_note) <p class="text-sm text-stone-600">Seller: {{ $return->seller_note }}</p> @endif

    @if ($forSeller && $return->status === \App\Models\ReturnRequest::REQUESTED)
        <p class="text-sm text-amber-800">Answer before {{ $return->respond_by->format('j M, g:ia') }}, or the WiBuy team decides.</p>
        <form method="POST" action="{{ route('seller.returns.accept', $return) }}" class="space-y-2">
            @csrf
            <label class="label" for="instructions">Accept: how should the buyer send it back?</label>
            <textarea id="instructions" name="instructions" required minlength="5" maxlength="500" rows="2" class="input" placeholder="Bring it to the shop at Siaka Stevens Street, or our rider collects it tomorrow."></textarea>
            <button class="btn btn-primary">Accept the return</button>
        </form>
        <form method="POST" action="{{ route('seller.returns.refuse', $return) }}" class="space-y-2">
            @csrf
            <label class="label" for="note">Or refuse, and say why (the WiBuy team will decide)</label>
            <textarea id="note" name="note" required minlength="5" maxlength="500" rows="2" class="input"></textarea>
            <button class="btn btn-danger">Refuse</button>
        </form>
    @elseif ($forSeller && $return->status === \App\Models\ReturnRequest::ACCEPTED)
        <form method="POST" action="{{ route('seller.returns.received', $return) }}" onsubmit="return confirm('You have the items back? The buyer is refunded now.')">
            @csrf
            <p class="text-sm">Confirm when the items are back with you (before {{ $return->send_back_by->format('j M') }}). The stock is added back and the buyer is refunded.</p>
            <button class="btn btn-primary">I have the items back</button>
        </form>
    @elseif ($return->dispute)
        <a href="{{ route('disputes.show', $return->dispute) }}" class="btn btn-outline btn-sm">See the problem report</a>
    @endif
</div>
