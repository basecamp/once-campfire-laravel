<?php

namespace App\Support;

use App\Http\Controllers\ChatController;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

final class ChatEvents
{
    /**
     * @param  string|null  $messageHtml  Pre-rendered message HTML with empty CSRF (broadcast-safe).
     *                                    When null, renders via MessageFragments with token ''.
     */
    public function created(Message $m, ?string $messageHtml = null): void
    {
        $html = $messageHtml ?? app(MessageFragments::class)->render([$m], token: '');
        app(Broadcasts::class)->room($m->room_id, app(ChatController::class)->stream('append', 'messages_room_'.$m->room_id, $html));
        // Opt 11: raw membership user ids — no Eloquent room/membership hydration.
        $userIds = DB::table('memberships')->where('room_id', $m->room_id)->pluck('user_id');
        app(Broadcasts::class)->publishMany($userIds->map(fn ($id) => ['user_'.$id.'_unreads', ['roomId' => $m->room_id]])->all());
    }
}
