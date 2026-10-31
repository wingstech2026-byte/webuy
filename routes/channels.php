<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Only people allowed to read a conversation may listen to it: anyone logged in for a deal chat,
// the customer and admins for support, the two people for a direct chat. The broadcast itself
// carries only a message id, and the browser fetches the message over HTTP where the same
// permission is checked again.
Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);

    return $conversation !== null && Gate::forUser($user)->allows('view', $conversation);
});
