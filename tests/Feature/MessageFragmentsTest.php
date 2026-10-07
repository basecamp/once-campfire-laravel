<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Support\MessageFragments;
use App\Support\MessageWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MessageFragmentsTest extends TestCase
{
    private function message(): Message
    {
        DB::unprepared(file_get_contents(database_path('schema.sql')));
        $user = User::create(['name' => 'Before', 'role' => 1, 'status' => 0]);
        $room = Room::create(['name' => 'Room', 'type' => 'Rooms::Open', 'creator_id' => $user->id]);

        Membership::create(['room_id' => $room->id, 'user_id' => $user->id, 'involvement' => 'mentions']);

        return app(MessageWriter::class)->create($room, $user, ['body' => 'token-one'], false);
    }

    public function test_cached_fragments_use_the_current_session_token_and_preserve_message_text(): void
    {
        $message = $this->message();
        $fragments = app(MessageFragments::class);
        session()->put('_token', 'token-one');
        $before = $fragments->render([$message]);
        session()->put('_token', 'token-two');
        $after = $fragments->render([$message->fresh()]);
        $this->assertStringContainsString('name="_token" value="token-one"', $before);
        $this->assertStringContainsString('name="_token" value="token-two"', $after);
        $this->assertStringNotContainsString('name="_token" value="token-one"', $after);
        $this->assertStringContainsString('token-one', $after);
        $this->assertSame('token-two', csrf_token());
        $this->assertIsArray(Cache::get($fragments->messageKey($message)));
        $this->assertStringNotContainsString('token-two', json_encode(Cache::get($fragments->messageKey($message))));
    }

    public function test_creator_changes_invalidate_a_warm_message_fragment(): void
    {
        $message = $this->message();
        $fragments = app(MessageFragments::class);
        $this->assertStringContainsString('Before', $fragments->render([$message]));
        DB::table('users')->where('id', $message->creator_id)->update(['name' => 'After', 'updated_at' => '2030-01-01 00:00:00']);
        $after = $fragments->render([$message->fresh()]);
        $this->assertStringContainsString('After', $after);
        $this->assertStringNotContainsString('title="Before"', $after);
    }
}
