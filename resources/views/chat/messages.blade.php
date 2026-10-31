@foreach ($messages as $message)
    @include('chat.message', ['message' => $message, 'viewer' => $viewer, 'conversation' => $conversation])
@endforeach
