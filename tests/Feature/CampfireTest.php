<?php

namespace Tests\Feature;

use App\Jobs\DeliverMessageNotifications;
use App\Models\Blob;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Support\BlobStorage;
use App\Support\Media;
use App\Support\MessageWriter;
use App\Support\Presence;
use App\Support\RailsCrypto;
use App\Support\RichTextRenderer;
use App\Support\SocketSessions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;

final class CampfireTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::unprepared(file_get_contents(database_path('schema.sql')));
        Queue::fake();
    }

    private function fixture(): array
    {
        $u = User::create(['name' => 'David', 'email_address' => 'david@example.org', 'password_digest' => password_hash('secret123456', PASSWORD_BCRYPT), 'role' => 1, 'status' => 0]);
        $room = Room::create(['name' => 'Watercooler', 'type' => 'Rooms::Open', 'creator_id' => $u->id]);
        Membership::create(['room_id' => $room->id, 'user_id' => $u->id, 'involvement' => 'mentions']);
        DB::table('accounts')->insert(['name' => 'Campfire', 'join_code' => 'abcd-efgh-ijkl', 'created_at' => now(), 'updated_at' => now()]);

        return [$u, $room];
    }

    private function auth(User $u): void
    {
        $token = 'local-fixture-session';
        DB::table('sessions')->insert(['token' => $token, 'user_id' => $u->id, 'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withUnencryptedCookie('session_token', app(RailsCrypto::class)->signCookie('session_token', $token));
    }

    public function test_browser_write_routes_reject_foreign_metadata_even_with_old_tokens(): void
    {
        [$user, $room] = $this->fixture();
        $this->auth($user);
        foreach ([
            ['POST', '/session'], ['POST', '/first_run'], ['POST', '/join/abcd-efgh-ijkl'],
            ['PATCH', '/session/transfers/invalid'], ['POST', '/rails/active_storage/direct_uploads'],
            ['POST', '/rooms/'.$room->id.'/messages'], ['PATCH', '/users/me/profile'],
            ['DELETE', '/session'], ['OPTIONS', '/rooms'], ['TRACE', '/rooms'],
        ] as [$method, $path]) {
            $this->call($method, $path, ['authenticity_token' => 'old-token'], [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->assertStatus(422);
        }
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('sessions', 1);
        $this->post('/rooms/'.$room->id.'/messages', ['message' => ['body' => 'Old tab works'], 'authenticity_token' => 'old-token'], ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_only_valid_bot_routes_and_signed_disk_capabilities_skip_browser_metadata(): void
    {
        [$user, $room] = $this->fixture();
        $bot = User::create(['name' => 'Bot', 'bot_token' => 'bot-secret', 'role' => 2, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id]);
        $path = '/rooms/'.$room->id.'/'.$bot->id.'-'.$bot->bot_token.'/messages';
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'null'], 'Bot message')->assertStatus(201);
        $this->call('POST', '/rooms/'.$room->id.'/'.$bot->id.'-invalid/messages', [], [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->assertStatus(422);
        $bot->update(['status' => 1]);
        $this->call('POST', $path, [], [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->assertStatus(422);
        $this->auth($user);
        $this->post('/rails/active_storage/direct_uploads', [], ['Sec-Fetch-Site' => 'cross-site'])->assertStatus(422);
        $this->call('PUT', '/rails/active_storage/disk/invalid', [], [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->assertStatus(422);
        $token = app(RailsCrypto::class)->appSign(['key' => 'absent', 'service_name' => 'local'], 'blob_token');
        $this->call('PUT', '/rails/active_storage/disk/'.$token, [], [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->assertNotFound();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_packaged_uploader_works_without_a_csrf_meta_tag(): void
    {
        $manifest = json_decode(file_get_contents(public_path('assets/.manifest.json')), true);
        $source = file_get_contents(resource_path('javascript/overrides/models/file_uploader.js'));
        $this->assertSame($source, file_get_contents(public_path('assets/'.$manifest['models/file_uploader.js'])));
        $this->assertStringNotContainsString('csrf-token', $source);
        $this->assertStringContainsString('req.send(formdata)', $source);
    }

    public function test_formatted_sound_commands_keep_their_plain_text_meaning(): void
    {
        [$user, $room] = $this->fixture();
        foreach (['<p>/p<strong>l</strong>ay bell</p>', '<p>&#47;&#112;lay bell</p>'] as $body) {
            $message = app(MessageWriter::class)->create($room, $user, ['body' => $body], false)->load('richText');
            $this->assertSame('/play bell', $message->plainText());
            $this->assertSame('bell.mp3', $message->sound()['asset']);
        }
        $unknown = app(MessageWriter::class)->create($room, $user, ['body' => '/play never_a_sound'], false)->load('richText');
        $this->assertNull($unknown->sound());
        $this->auth($user);
        $response = $this->get('/rooms/'.$room->id)->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), 'data-controller="sound"'));
    }

    public function test_direct_room_heading_names_the_other_participant_for_screen_readers(): void
    {
        [$user] = $this->fixture();
        $other = User::create(['name' => 'Other participant', 'role' => 0, 'status' => 0]);
        $room = Room::create(['type' => 'Rooms::Direct', 'creator_id' => $user->id]);
        Membership::create(['room_id' => $room->id, 'user_id' => $user->id]);
        Membership::create(['room_id' => $room->id, 'user_id' => $other->id]);
        $this->auth($user);
        $this->get('/rooms/'.$room->id)->assertOk()->assertSee('<span class="for-screen-reader">Ping with </span>Other participant', false);
    }

    public function test_session_transfer_automatically_submits_without_signing_in_on_get(): void
    {
        [$user, $room] = $this->fixture();
        $id = app(RailsCrypto::class)->signedId($user->id, 'User', 'transfer', now()->addHours(4)->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
        $path = '/session/transfers/'.$id;
        $this->get($path)->assertOk()->assertSee('data-controller="auto-submit"', false)->assertSee('</form>', false)->assertSee('auto-submit', false);
        $this->assertDatabaseCount('sessions', 0);
        $this->patch($path)->assertRedirect('/');
        $this->assertDatabaseCount('sessions', 1);
        $this->assertSame($user->id, DB::table('sessions')->value('user_id'));
    }

    public function test_custom_styles_apply_to_room_profile_and_account_pages_after_updates(): void
    {
        [$user, $room] = $this->fixture();
        $this->auth($user);
        $styles = 'body { --custom-style-test: first; }';
        $this->patch('/account/custom_styles', ['account' => ['custom_styles' => $styles]])->assertRedirect('/account/edit');
        foreach (['/rooms/'.$room->id, '/users/me/profile', '/account/edit'] as $path) {
            $this->get($path)->assertOk()->assertSee('<style>'.$styles.'</style>', false);
        }
        $changed = 'body { --custom-style-test: second; }';
        $this->patch('/account/custom_styles', ['account' => ['custom_styles' => $changed]])->assertRedirect('/account/edit');
        $this->get('/rooms/'.$room->id)->assertOk()->assertSee('<style>'.$changed.'</style>', false)->assertDontSee($styles, false);
    }

    public function test_search_reaches_sparse_memberships_and_quotes_literal_terms(): void
    {
        [$user, $room] = $this->fixture();
        $visible = app(MessageWriter::class)->create($room, $user, ['body' => '<p>searchsparseonly</p>']);
        $private = Room::create(['name' => 'Private', 'type' => 'Rooms::Closed', 'creator_id' => $user->id]);
        DB::transaction(function () use ($private, $user) {
            for ($i = 0; $i < 1100; $i++) {
                $id = DB::table('messages')->insertGetId(['room_id' => $private->id, 'creator_id' => $user->id, 'client_message_id' => 'sparse-'.$i, 'created_at' => now(), 'updated_at' => now()]);
                DB::insert('INSERT INTO message_search_index(rowid,body) VALUES (?,?)', [$id, 'searchsparseonly']);
            }
        });
        $this->assertSame([$visible->id], Message::searchFor($user, 'searchsparseonly')->pluck('id')->all());
        Membership::where('user_id', $user->id)->where('room_id', $room->id)->delete();
        $this->assertCount(0, Message::searchFor($user, 'searchsparseonly'));
        Membership::create(['user_id' => $user->id, 'room_id' => $private->id]);
        $this->assertCount(100, Message::searchFor($user, 'searchsparseonly'));
        $this->assertCount(0, Message::searchFor($user, 'searchsparseonly AND'));
    }

    /** Moves the app onto a WAL database file that a second, foreign connection can write. */
    private function sharedDatabase(): \PDO
    {
        $directory = storage_path('framework/testing/revocation-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0755, true);
        $database = $directory.'/application.sqlite3';
        DB::statement('VACUUM INTO '.DB::connection()->getPdo()->quote($database));
        config(['database.connections.sqlite.database' => $database]);
        DB::purge();
        $this->beforeApplicationDestroyed(fn () => (new Process(['rm', '-rf', $directory]))->mustRun());
        $foreign = new \PDO('sqlite:'.$database);
        $foreign->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=10000');

        return $foreign;
    }

    private function warmSession(User $user, string $token, Room $room): string
    {
        DB::table('sessions')->insert(['token' => $token, 'user_id' => $user->id, 'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $cookie = app(RailsCrypto::class)->signCookie('session_token', $token);
        for ($i = 0; $i < 2; $i++) {
            $this->withUnencryptedCookie('session_token', $cookie)->get('/rooms/'.$room->id)->assertOk();
        }

        return $cookie;
    }

    public function test_message_writes_index_room_unread_and_notifications_after_commit(): void
    {
        [$u,$room] = $this->fixture();
        $other = User::create(['name' => 'Jason', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $other->id, 'involvement' => 'everything']);
        $message = app(MessageWriter::class)->create($room, $u, ['body' => '<p>Coffee and chatting</p>']);
        $this->assertSame('Coffee and chatting', $message->fresh()->plainText());
        $this->assertSame($message->id, (int) DB::selectOne("SELECT rowid FROM message_search_index WHERE body MATCH 'coffee'")->rowid);
        $this->assertNotNull($room->memberships()->where('user_id', $other->id)->value('unread_at'));
        Queue::assertPushed(DeliverMessageNotifications::class);
        app(MessageWriter::class)->update($message, ['body' => '<p>Tea</p>']);
        $this->assertSame(0, count(DB::select("SELECT rowid FROM message_search_index WHERE body MATCH 'coffee'")));
        app(MessageWriter::class)->destroy($message);
        $this->assertSame(0, DB::table('messages')->count());
        $this->assertSame(0, DB::table('action_text_rich_texts')->count());
    }

    public function test_authentication_and_populated_room_html(): void
    {
        [$u,$room] = $this->fixture();
        app(MessageWriter::class)->create($room, $u, ['body' => '<p>Hello Campfire</p>']);
        $this->get('/up')->assertOk()->assertSee('background-color: green');
        $this->get('/up.json')->assertOk()->assertJsonPath('status', 'up');
        $this->get('/rooms/'.$room->id)->assertRedirect('/session/new');
        $this->auth($u);
        $this->get('/rooms/'.$room->id)->assertOk()->assertSee('Hello Campfire')->assertSee('RoomMessagesChannel');
        $this->get('/users/me/sidebar')->assertOk()->assertSee('Watercooler');
        $this->get('/searches?q=Hello')->assertOk()->assertSee('Hello Campfire');
    }

    public function test_nonmember_cannot_read_or_write_even_open_rooms(): void
    {
        [$u,$room] = $this->fixture();
        $stranger = User::create(['name' => 'Stranger', 'role' => 0, 'status' => 0]);
        $this->auth($stranger);
        $this->get('/rooms/'.$room->id)->assertNotFound();
        $this->post('/rooms/'.$room->id.'/messages', ['message' => ['body' => 'forbidden']])->assertNotFound();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_message_edit_permission_and_cross_room_ids(): void
    {
        [$u,$room] = $this->fixture();
        $m = app(MessageWriter::class)->create($room, $u, ['body' => 'hello']);
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $member->id, 'involvement' => 'mentions']);
        $this->auth($member);
        $this->patch('/rooms/'.$room->id.'/messages/'.$m->id, ['message' => ['body' => 'stolen']])->assertForbidden();
        $this->delete('/rooms/'.$room->id.'/messages/'.$m->id)->assertForbidden();
    }

    public function test_direct_room_cannot_change_audience_or_type(): void
    {
        [$u,$room] = $this->fixture();
        $direct = Room::create(['name' => null, 'type' => 'Rooms::Direct', 'creator_id' => $u->id]);
        Membership::create(['room_id' => $direct->id, 'user_id' => $u->id, 'involvement' => 'everything']);
        $this->auth($u);
        $this->patch('/rooms/opens/'.$direct->id, ['room' => ['name' => 'public']])->assertNotFound();
        $this->assertSame('Rooms::Direct', $direct->fresh()->type);
    }

    public function test_rollback_does_not_enqueue_notifications(): void
    {
        [$u,$room] = $this->fixture();
        try {
            DB::transaction(function () use ($u, $room) {
                app(MessageWriter::class)->create($room, $u, ['body' => 'rollback']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_mentions_preserve_signed_reference_and_safe_html(): void
    {
        [$u,$room] = $this->fixture();
        $sgid = app(RailsCrypto::class)->sgid($u->id);
        $body = '<p>Hi <action-text-attachment sgid="'.$sgid.'" content-type="application/vnd.campfire.mention"></action-text-attachment><script>alert(1)</script></p>';
        $m = app(MessageWriter::class)->create($room, $u, ['body' => $body]);
        $stored = $m->fresh()->richText->body;
        $this->assertStringContainsString('action-text-attachment', $stored);
        $renderer = app(RichTextRenderer::class);
        $this->assertSame([$u->id], $renderer->mentions($stored));
        $this->assertStringContainsString('David', $renderer->html($stored));
        $this->assertStringNotContainsString('<script', $renderer->html($stored));
    }

    public function test_native_bot_raw_body_api_and_boost(): void
    {
        [$u,$room] = $this->fixture();
        $bot = User::create(['name' => 'Bender', 'bot_token' => 'BenderBot123', 'role' => 2, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'mentions']);
        $path = '/rooms/'.$room->id.'/'.$bot->id.'-'.$bot->bot_token.'/messages';
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'Coffee from Bender')->assertStatus(201);
        $m = Message::first();
        $this->assertSame($bot->id, $m->creator_id);
        $this->assertSame('Coffee from Bender', $m->plainText());
        $this->call('POST', $path.'/'.$m->id.'/boosts', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '👍')->assertStatus(201);
        $this->assertDatabaseHas('boosts', ['booster_id' => $bot->id, 'message_id' => $m->id, 'content' => '👍']);
        $this->get('/rooms/'.$room->id.'?bot_key='.$bot->id.'-'.$bot->bot_token)->assertForbidden();
    }

    public function test_webhook_callback_reply_is_real_native_message_and_does_not_loop(): void
    {
        [$u,$room] = $this->fixture();
        $room->update(['type' => 'Rooms::Direct']);
        $bot = User::create(['name' => 'Bender', 'bot_token' => 'BenderBot123', 'role' => 2, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'everything']);
        DB::table('webhooks')->insert(['user_id' => $bot->id, 'url' => 'http://fixture.test/hook', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['fixture.test/*' => Http::response('Hello from bot', 200, ['Content-Type' => 'text/plain'])]);
        $m = app(MessageWriter::class)->create($room, $u, ['body' => 'Hi bot'], true);
        (new DeliverMessageNotifications($m->id, true))->handle();
        $this->assertSame(2, Message::count());
        $reply = Message::where('creator_id', $bot->id)->first();
        $this->assertSame('Hello from bot', $reply->plainText());
        Http::assertSent(fn ($r) => $r->url() === 'http://fixture.test/hook' && $r['message']['id'] === $m->id);
        Queue::assertPushed(DeliverMessageNotifications::class, fn ($job) => $job->messageId === $reply->id && ! $job->webhooks);
    }

    public function test_real_image_upload_is_analyzed_and_synchronously_thumbnailed(): void
    {
        [$user, $room] = $this->fixture();
        $directory = storage_path('framework/testing/media-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0755, true);
        config(['campfire.files' => $directory]);
        $source = $directory.'/pixel.png';
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aY2kAAAAASUVORK5CYII='));
        try {
            $upload = new UploadedFile($source, 'pixel.png', 'image/png', null, true);
            $message = app(MessageWriter::class)->create($room, $user, ['body' => '', 'attachment' => $upload]);
            $blob = $message->attachment->blob;
            $metadata = json_decode($blob->metadata, true);
            $this->assertSame($message->id, (int) DB::selectOne("SELECT rowid FROM message_search_index WHERE body MATCH 'pixel'")->rowid);
            $this->assertSame(1, $metadata['width']);
            $this->assertSame(1, $metadata['height']);
            $this->assertSame(base64_encode(md5(file_get_contents($source), true)), $blob->checksum);
            $variant = app(Media::class)->variant($blob, ['resize_to_limit' => [1200, 800], 'format' => 'webp']);
            $this->assertFileExists($variant);
            $this->assertSame('image/webp', mime_content_type($variant));
            app(MessageWriter::class)->destroy($message);
            $this->assertDatabaseCount('active_storage_blobs', 0);
            $this->assertFileDoesNotExist($variant);
            $this->assertFileDoesNotExist(app(BlobStorage::class)->path($blob));
            DB::beginTransaction();
            $pending = app(MessageWriter::class)->create($room, $user, ['attachment' => $upload]);
            $pendingBlob = $pending->attachment->blob;
            $pendingPath = app(BlobStorage::class)->path($pendingBlob);
            $this->assertFileExists($pendingPath);
            DB::rollBack();
            $this->assertFileDoesNotExist($pendingPath);
            $this->assertDatabaseCount('messages', 0);
            $this->assertDatabaseCount('active_storage_blobs', 0);
            app(BlobStorage::class)->attachTo('User', $user->id, 'avatar', $upload);
            $avatar = $this->get('/users/'.$user->avatarToken().'/avatar')->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeaderMissing('Location');
            $this->assertStringContainsString('max-age=1800', $avatar->headers->get('Cache-Control'));
            $this->get('/users/'.$user->avatarToken().'/avatar', ['If-None-Match' => $avatar->headers->get('ETag')])->assertStatus(304);
            $this->assertStringContainsString('?v=', $user->fresh()->avatarUrl());
            $bot = User::create(['name' => 'Robot', 'role' => 2, 'status' => 0]);
            $this->get('/users/'.$bot->avatarToken().'/avatar')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertHeaderMissing('Location');
        } finally {
            (new Process(['rm', '-rf', $directory]))->mustRun();
        }
    }

    public function test_failed_image_analysis_rolls_back_records_and_files(): void
    {
        [$user, $room] = $this->fixture();
        $directory = storage_path('framework/testing/failure-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0755, true);
        config(['campfire.files' => $directory]);
        $source = $directory.'/source.txt';
        file_put_contents($source, 'fixture upload');
        $this->app->instance(Media::class, new class
        {
            public function variant(): string
            {
                throw new \RuntimeException('Analysis failure');
            }
        });
        $upload = new class($source, 'picture.png', 'image/png', null, true) extends UploadedFile
        {
            public function getMimeType(): ?string
            {
                return 'image/png';
            }
        };
        try {
            app(MessageWriter::class)->create($room, $user, ['attachment' => $upload]);
            $this->fail('Analysis failure must abort the transaction');
        } catch (\RuntimeException $error) {
            $this->assertSame('Analysis failure', $error->getMessage());
            $this->assertDatabaseCount('messages', 0);
            $this->assertDatabaseCount('active_storage_blobs', 0);
            $files = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)));
            $this->assertCount(1, array_filter($files, fn ($file) => $file->isFile()));
            Queue::assertNothingPushed();
        } finally {
            (new Process(['rm', '-rf', $directory]))->mustRun();
        }
    }

    public function test_fractional_timestamp_pagination_excludes_its_cursor(): void
    {
        [$user, $room] = $this->fixture();
        $this->auth($user);
        $messages = [];
        foreach (['100000', '200000', '300000'] as $fraction) {
            $message = app(MessageWriter::class)->create($room, $user, ['body' => $fraction]);
            $message->update(['created_at' => '2026-01-01 12:00:00.'.$fraction]);
            $messages[] = $message;
        }
        $before = $this->get('/rooms/'.$room->id.'/messages?before='.$messages[1]->id, ['Accept' => 'application/json'])->assertOk()->json();
        $after = $this->get('/rooms/'.$room->id.'/messages?after='.$messages[1]->id, ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([$messages[0]->id], array_column($before, 'id'));
        $this->assertSame([$messages[2]->id], array_column($after, 'id'));
        foreach ($messages as $index => $message) {
            DB::table('messages')->where('id', $message->id)->update(['created_at' => '2026-01-01 12:00:0'.$index]);
        }
        $before = $this->get('/rooms/'.$room->id.'/messages?before='.$messages[1]->id, ['Accept' => 'application/json'])->assertOk()->json();
        $after = $this->get('/rooms/'.$room->id.'/messages?after='.$messages[1]->id, ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([$messages[0]->id], array_column($before, 'id'));
        $this->assertSame([$messages[2]->id], array_column($after, 'id'));
    }

    public function test_presence_refresh_visibility_and_multiple_tabs_preserve_read_contract(): void
    {
        [$user, $room] = $this->fixture();
        $presence = app(Presence::class);
        $membership = $room->memberships()->first();
        $membership->update(['unread_at' => now()]);
        $this->travelTo(now()->startOfSecond());
        try {
            $presence->present($user->id, $room->id);
            $presence->present($user->id, $room->id);
            $this->assertSame(2, $membership->fresh()->connections);
            $this->assertNull($membership->fresh()->unread_at);
            $events = file(config('campfire.events'), FILE_IGNORE_NEW_LINES);
            $last = json_decode(end($events), true);
            $this->assertSame(['room_id' => $room->id], $last['message']);
            $this->travel(50)->seconds();
            $presence->refresh($user->id, $room->id);
            $this->assertSame(2, $membership->fresh()->connections);
            $this->assertSame(now()->format('Y-m-d H:i:s.u'), $membership->fresh()->getRawOriginal('connected_at'));
            $presence->absent($user->id, $room->id);
            $this->assertSame(1, $membership->fresh()->connections);
            $this->assertNotNull($membership->fresh()->connected_at);
            $presence->absent($user->id, $room->id);
            $this->assertSame(0, $membership->fresh()->connections);
            $this->assertNull($membership->fresh()->connected_at);
            $presence->present($user->id, $room->id);
            $this->travel(61)->seconds();
            $presence->refresh($user->id, $room->id);
            $this->assertSame(1, $membership->fresh()->connections);
            $this->travel(61)->seconds();
            $presence->present($user->id, $room->id);
            $this->assertSame(1, $membership->fresh()->connections);
            $this->travel(61)->seconds();
            $presence->absent($user->id, $room->id);
            $this->assertSame(0, $membership->fresh()->connections);
        } finally {
            $this->travelBack();
        }
    }

    public function test_blob_serving_matches_installed_rails_mime_and_disposition_policy(): void
    {
        $directory = storage_path('framework/testing/serving-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0755, true);
        config(['campfire.files' => $directory]);
        try {
            foreach (['text/html' => ['application/octet-stream', 'attachment'], 'image/svg+xml' => ['application/octet-stream', 'attachment'], 'application/xml' => ['application/octet-stream', 'attachment'], 'image/png' => ['image/png', 'inline'], 'application/pdf' => ['application/pdf', 'inline'], 'audio/mpeg' => ['audio/mpeg', 'attachment'], 'video/mp4' => ['video/mp4', 'attachment'], 'text/plain' => ['text/plain', 'attachment']] as $mime => [$type, $disposition]) {
                $blob = Blob::create(['key' => bin2hex(random_bytes(14)), 'filename' => 'fixture.txt', 'content_type' => $mime, 'byte_size' => 7, 'service_name' => 'local', 'metadata' => '{}', 'created_at' => now()]);
                $path = app(BlobStorage::class)->path($blob);
                mkdir(dirname($path), 0755, true);
                file_put_contents($path, 'fixture');
                $response = $this->get(app(BlobStorage::class)->url($blob))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
                $this->assertSame($type, strtok($response->headers->get('Content-Type'), ';'));
                $this->assertStringStartsWith($disposition, $response->headers->get('Content-Disposition'));
            }
        } finally {
            (new Process(['rm', '-rf', $directory]))->mustRun();
        }
    }

    public function test_concurrent_native_message_writers_do_not_upgrade_read_snapshots(): void
    {
        [$user, $room] = $this->fixture();
        $directory = storage_path('framework/testing/concurrency-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0755, true);
        $database = $directory.'/application.sqlite3';
        DB::statement('VACUUM INTO '.DB::connection()->getPdo()->quote($database));
        $initial = new \PDO('sqlite:'.$database);
        $initial->exec('PRAGMA journal_mode=WAL');
        $initial = null;
        $script = $directory.'/writer.php';
        $code = '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; $app = require '.var_export(base_path('bootstrap/app.php'), true).';';
        $code .= <<<'PHP'
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $argv[1]]);
Illuminate\Support\Facades\DB::purge();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\DB::listen(function ($query) {
    if (str_contains($query->sql, 'memberships') && Illuminate\Support\Facades\DB::transactionLevel() > 0) {
        usleep(20000);
    }
});
$user = App\Models\User::findOrFail($argv[2]);
$room = App\Models\Room::findOrFail($argv[3]);
for ($index = 0; $index < 8; $index++) {
    app(App\Support\MessageWriter::class)->create($room, $user, ['body' => 'Concurrent native coffee']);
}
echo 'completed:8';
PHP;
        file_put_contents($script, $code);
        $processes = [];
        try {
            for ($index = 0; $index < 4; $index++) {
                $process = new Process([PHP_BINARY, $script, $database, (string) $user->id, (string) $room->id]);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $this->assertStringContainsString('completed:8', $process->getOutput(), $process->getErrorOutput());
            }
            $connection = new \PDO('sqlite:'.$database);
            $this->assertSame(32, (int) $connection->query('SELECT COUNT(*) FROM messages')->fetchColumn());
            $this->assertSame(32, (int) $connection->query('SELECT COUNT(*) FROM message_search_index')->fetchColumn());
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            (new Process(['rm', '-rf', $directory]))->mustRun();
        }
    }

    public function test_pending_socket_survives_background_checks_but_expiration_and_revocation_close_it(): void
    {
        [$user] = $this->fixture();
        $sessions = app(SocketSessions::class);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $connection = new TcpConnection(new Select, $sockets[0]);
        $connection->handshakeDeadline = 100;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertFalse($sessions->admitted($connection, 99));
        $this->assertFalse($sessions->admitted($connection, 99.9));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(TcpConnection::STATUS_ESTABLISHED, $connection->getStatus());
        $this->assertFalse($sessions->admitted($connection, 100));
        $this->assertSame(TcpConnection::STATUS_CLOSED, $connection->getStatus());
        fclose($sockets[1]);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $connection = new TcpConnection(new Select, $sockets[0]);
        $connection->handshakeDeadline = 100;
        $connection->userId = $user->id;
        $connection->sessionId = DB::table('sessions')->insertGetId(['token' => 'socket-fixture', 'user_id' => $user->id, 'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($sessions->admitted($connection, 101));
        $this->assertSame(TcpConnection::STATUS_ESTABLISHED, $connection->getStatus());
        DB::table('sessions')->where('id', $connection->sessionId)->delete();
        $this->assertFalse($sessions->admitted($connection, 102));
        $this->assertSame(TcpConnection::STATUS_CLOSED, $connection->getStatus());
        fclose($sockets[1]);
    }

    public function test_signing_in_discards_the_guest_session_cookie(): void
    {
        $this->fixture();
        config(['session.driver' => 'cookie']);
        $guestId = $this->get('/session/new')->getCookie(config('session.cookie'))->getValue();

        $this->withCookie(config('session.cookie'), $guestId)
            ->post('/session', ['email_address' => 'david@example.org', 'password' => 'secret123456'])
            ->assertRedirect('/')
            ->assertCookieExpired($guestId);
    }

    public function test_sidebar_is_a_complete_page_for_the_current_viewer(): void
    {
        [$user] = $this->fixture();
        $other = User::create(['name' => 'Other participant', 'role' => 0, 'status' => 0]);
        $direct = Room::create(['type' => 'Rooms::Direct', 'creator_id' => $user->id]);
        Membership::create(['room_id' => $direct->id, 'user_id' => $user->id]);
        Membership::create(['room_id' => $direct->id, 'user_id' => $other->id]);
        $this->auth($user);
        $response = $this->get('/users/me/sidebar')->assertOk();
        $response->assertSee('<!DOCTYPE html>', false);
        $response->assertSee('name="current-user-id" content="'.$user->id.'"', false);
        $response->assertSee('id="user_sidebar"', false);
        $response->assertSee('</html>', false);

        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@id="user_sidebar"]//turbo-frame[@id="direct_rooms_control" and @target="_top"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="direct_rooms_control"]//a[@href="/rooms/directs/new" and @data-turbo-frame="direct_rooms_control"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="direct_rooms_control"]//*[@id="direct_rooms"]//a[@id="list_room_'.$direct->id.'"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="direct_rooms_control"]//*[@id="shared_rooms"]')->length);
    }

    public function test_logging_out_rejects_the_warm_session_cookie_on_the_next_request(): void
    {
        [$user, $room] = $this->fixture();
        $this->sharedDatabase();
        $cookie = $this->warmSession($user, 'logout-session', $room);

        $this->withUnencryptedCookie('session_token', $cookie)->delete('/session')->assertRedirect();

        $this->withUnencryptedCookie('session_token', $cookie)->get('/rooms/'.$room->id)->assertRedirect('/session/new');
    }

    public function test_banning_a_user_rejects_their_warm_session_on_the_next_request(): void
    {
        [$admin, $room] = $this->fixture();
        $member = User::create(['name' => 'Jason', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $member->id]);
        $this->sharedDatabase();
        $memberCookie = $this->warmSession($member, 'member-session', $room);
        $adminCookie = $this->warmSession($admin, 'admin-session', $room);

        $this->withUnencryptedCookie('session_token', $adminCookie)->post('/users/'.$member->id.'/ban')->assertRedirect('/users/'.$member->id);

        $this->withUnencryptedCookie('session_token', $memberCookie)->get('/rooms/'.$room->id)->assertRedirect('/session/new');
    }

    public function test_foreign_sqlite_revocations_reject_warm_sessions_on_the_next_request(): void
    {
        [$user, $room] = $this->fixture();
        $foreign = $this->sharedDatabase();
        $revocations = [
            'session deleted' => fn () => $foreign->exec("DELETE FROM sessions WHERE token = 'foreign-session'"),
            'user banned' => fn () => $foreign->exec('UPDATE users SET status = 2 WHERE id = '.$user->id),
            'user deactivated' => fn () => $foreign->exec('UPDATE users SET status = 1 WHERE id = '.$user->id),
        ];
        foreach ($revocations as $revocation => $revoke) {
            $foreign->exec("DELETE FROM sessions WHERE token = 'foreign-session'");
            $foreign->exec('UPDATE users SET status = 0 WHERE id = '.$user->id);
            $cookie = $this->warmSession($user, 'foreign-session', $room);

            $revoke();

            $response = $this->withUnencryptedCookie('session_token', $cookie)->get('/rooms/'.$room->id);
            $this->assertTrue($response->isRedirect(url('/session/new')), $revocation.' still authenticated with status '.$response->getStatusCode());
        }
    }
}
