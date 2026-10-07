<?php

namespace App\Support;

use App\Http\Controllers\ChatController;
use App\Models\Message;

final class ChatEvents
{
    public function created(Message $m): string
    {
        $m->load(['creator', 'room.users', 'richText', 'boosts.booster', 'attachment.blob']);
        $html = view('messages.message', ['message' => $m])->render();
        $s = app(ChatController::class)->stream('append', 'messages_room_'.$m->room_id, $html);
        app(Broadcasts::class)->room($m->room_id, $s);
        foreach ($m->room->memberships()->pluck('user_id') as $id) {
            app(Broadcasts::class)->publish('user_'.$id.'_unreads', ['roomId' => $m->room_id]);
        }

        return $s;
    }
}
