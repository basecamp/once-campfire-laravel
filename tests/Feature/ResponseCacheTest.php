<?php

namespace Tests\Feature;

use App\Models\Boost;
use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\ChatEvents;
use App\Support\MessageFragments;
use App\Support\MessageWriter;
use App\Support\RailsCrypto;
use App\Support\ResponseCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use PDO;
use Tests\TestCase;

final class ResponseCacheTest extends TestCase
{
    private string $database;

    private User $user;

    private Room $room;

    private PDO $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'campfire-response-');
        config(['database.connections.sqlite.database' => $this->database]);
        DB::purge();
        DB::unprepared(file_get_contents(database_path('schema.sql')));
        Queue::fake();
        $this->user = User::create(['name' => 'Before', 'role' => 1, 'status' => 0]);
        $this->room = Room::create(['name' => 'Room', 'type' => 'Rooms::Open', 'creator_id' => $this->user->id]);
        Membership::create(['room_id' => $this->room->id, 'user_id' => $this->user->id, 'involvement' => 'mentions']);
        DB::table('accounts')->insert(['name' => 'Campfire', 'join_code' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['token' => 'cache-test', 'user_id' => $this->user->id, 'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withUnencryptedCookie('session_token', app(RailsCrypto::class)->signCookie('session_token', 'cache-test'));
        $this->foreign = new PDO('sqlite:'.$this->database);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ([$this->database, $this->database.'-wal', $this->database.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function csrf(string $token): void
    {
        $this->withUnencryptedCookie('_campfire_session', app(RailsCrypto::class)->encryptCookie('_campfire_session', ['_csrf_token' => $token, 'session_id' => 'cache-test']));
    }

    public function test_hot_body_reuses_rendering_but_keeps_fresh_tokens_cookies_and_literal_text(): void
    {
        $first = base64_encode(str_repeat('a', 32));
        $second = base64_encode(str_repeat('b', 32));
        app(MessageWriter::class)->create($this->room, $this->user, ['body' => $first], false);
        $renders = 0;
        View::composer('rooms.show', function () use (&$renders) {
            $renders++;
        });
        $this->csrf($first);
        $this->get('/rooms/'.$this->room->id)->assertOk()->assertSee('content="'.$first.'"', false);
        $this->csrf($second);
        $response = $this->get('/rooms/'.$this->room->id)->assertOk();
        $response->assertSee('content="'.$second.'"', false)->assertSee($first, false);
        $this->assertSame(1, $renders);
        $this->assertSame($second, session()->token());
        $response->assertCookie('last_room');
        $payload = app(RailsCrypto::class)->decryptCookie('_campfire_session', $response->getCookie('_campfire_session', false)->getValue());
        $this->assertSame($second, $payload['_csrf_token']);
    }

    public function test_local_and_foreign_commits_refresh_profiles_styles_and_unversioned_message_text(): void
    {
        $message = app(MessageWriter::class)->create($this->room, $this->user, ['body' => 'old body'], false);
        $this->get('/rooms/'.$this->room->id)->assertOk()->assertSee('old body');
        $this->foreign->exec("UPDATE users SET name='Foreign name'; UPDATE accounts SET custom_styles='body { color: red; }'; UPDATE action_text_rich_texts SET body='foreign body'");
        $this->get('/rooms/'.$this->room->id)->assertOk()->assertSee('Foreign name')->assertSee('foreign body')->assertSee('body { color: red; }', false)->assertDontSee('old body');
        DB::table('action_text_rich_texts')->where('record_id', $message->id)->update(['body' => 'local body']);
        $this->get('/rooms/'.$this->room->id)->assertOk()->assertSee('local body')->assertDontSee('foreign body');
    }

    public function test_warm_cache_never_supplies_room_permissions_or_revoked_authentication(): void
    {
        $this->get('/rooms/'.$this->room->id)->assertOk();
        $this->foreign->exec('DELETE FROM memberships');
        $this->get('/rooms/'.$this->room->id)->assertNotFound();
        Membership::create(['room_id' => $this->room->id, 'user_id' => $this->user->id]);
        $this->get('/rooms/'.$this->room->id)->assertOk();
        $this->foreign->exec('DELETE FROM sessions');
        $this->get('/rooms/'.$this->room->id)->assertRedirect('/session/new');
    }

    public function test_auth_to_lookup_and_mid_render_commits_are_not_admitted(): void
    {
        $changed = false;
        DB::listen(function ($query) use (&$changed): void {
            if (! $changed && str_contains($query->sql, 'FROM sessions s JOIN users u')) {
                $changed = true;
                $this->foreign->exec("UPDATE users SET name='After auth'");
            }
        });
        $this->get('/rooms/'.$this->room->id)->assertOk();
        $this->assertTrue($changed, 'The foreign commit must occur after the authentication snapshot.');
        $this->get('/rooms/'.$this->room->id)->assertOk()->assertSee('After auth');
        $renders = 0;
        View::composer('rooms.show', function () use (&$renders): void {
            if ($renders++ === 0) {
                $this->foreign->exec("UPDATE users SET name='After render'");
            }
        });
        app(ResponseCache::class)->clear();
        $this->get('/rooms/'.$this->room->id)->assertOk();
        $this->get('/rooms/'.$this->room->id)->assertOk()->assertSee('After render');
        $this->assertSame(2, $renders);
    }

    public function test_joined_authentication_reads_once_and_keeps_fresh_session_user_and_role(): void
    {
        $path = '/rooms/'.$this->room->id;
        $this->get($path)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($path)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $auth = array_filter($queries, fn ($query) => str_contains($query['query'], 'FROM sessions s JOIN users u'));
        $this->assertCount(1, $auth);
        $this->assertSame(['cache-test'], array_values($auth)[0]['bindings']);
        $session = DB::table('sessions')->where('token', 'cache-test')->first();
        $this->assertSame($session->id, request()->attributes->get('campfire.session_id'));
        $this->assertSame($this->user->id, request()->user()->id);
        $this->assertNull(request()->user()->getAttribute('campfire_session_id'));

        $this->foreign->exec('UPDATE users SET role=2');
        $this->get($path)->assertForbidden();
        $this->foreign->exec('UPDATE users SET role=1');
        foreach ([1, 2] as $status) {
            $this->get($path)->assertOk();
            $this->foreign->exec('UPDATE users SET status='.$status);
            $this->get($path)->assertRedirect('/session/new');
            $this->foreign->exec('UPDATE users SET status=0');
        }
        $other = User::create(['name' => 'Another viewer', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $this->room->id, 'user_id' => $other->id]);
        $this->foreign->exec('UPDATE sessions SET user_id='.$other->id);
        $this->get($path)->assertOk()->assertSee('content="Another viewer"', false);
        $this->assertSame($other->id, request()->user()->id);
        $this->foreign->exec('DELETE FROM sessions');
        $this->get($path)->assertRedirect('/session/new');
    }

    public function test_variants_and_disable_setting_keep_native_paths(): void
    {
        app(MessageWriter::class)->create($this->room, $this->user, ['body' => 'message'], false);
        $this->get('/rooms/'.$this->room->id.'/messages')->assertOk()->assertSee('message');
        $this->get('/rooms/'.$this->room->id.'/messages', ['Accept' => 'application/json'])->assertOk()->assertJsonPath('0.body.plain_text', 'message');
        $this->get('/rooms/'.$this->room->id, ['Turbo-Frame' => 'user_sidebar'])->assertOk();
        config(['campfire.response_cache_mb' => 0]);
        $renders = 0;
        View::composer('rooms.show', function () use (&$renders) {
            $renders++;
        });
        $this->get('/rooms/'.$this->room->id)->assertOk();
        $this->get('/rooms/'.$this->room->id)->assertOk();
        $this->assertSame(2, $renders);
        $cache = app(ResponseCache::class);
        $this->assertNull($cache->epoch());
    }

    public function test_conditional_and_flash_requests_keep_native_rendering(): void
    {
        $renders = 0;
        View::composer('rooms.show', function () use (&$renders) {
            $renders++;
        });
        $path = '/rooms/'.$this->room->id;
        $this->get($path)->assertOk();
        $this->get($path)->assertOk();
        $this->assertSame(1, $renders);
        $this->get($path, ['If-None-Match' => 'unmatched'])->assertOk();
        $this->assertSame(2, $renders);
        $this->withSession(['notice' => 'fresh native flash'])->get($path)->assertOk()->assertSee('fresh native flash');
        $this->assertSame(3, $renders);
    }

    public function test_budget_and_epoch_admission_are_bounded(): void
    {
        config(['campfire.response_cache_mb' => 1]);
        $cache = app(ResponseCache::class);
        $epoch = $cache->epoch();
        $cache->put('large', $epoch, ['body' => str_repeat('x', 1024 * 1024)]);
        $this->assertNull($cache->get('large', $epoch));
        foreach (['one', 'two', 'three'] as $key) {
            $cache->put($key, $epoch, ['body' => str_repeat('x', 400000)]);
        }
        $this->assertNull($cache->get('one', $epoch));
        $this->assertNotNull($cache->get('three', $epoch));
        $this->foreign->exec("UPDATE accounts SET name='Committed'");
        $cache->put('stale', $epoch, ['body' => 'old']);
        $this->assertNull($cache->get('stale', $cache->epoch()));
    }

    public function test_native_conditional_flash_and_disabled_reads_refresh_unversioned_fragments(): void
    {
        $message = app(MessageWriter::class)->create($this->room, $this->user, ['body' => 'original fragment'], false);
        $path = '/rooms/'.$this->room->id;
        $headers = ['If-None-Match' => 'unmatched'];
        $this->get($path, $headers)->assertOk()->assertSee('original fragment');
        $this->foreign->exec("UPDATE action_text_rich_texts SET body='conditional foreign fragment'; UPDATE users SET name='Foreign creator'");
        $this->get($path, $headers)->assertOk()->assertSee('conditional foreign fragment')->assertSee('Foreign creator')->assertDontSee('original fragment');

        $this->withSession(['notice' => 'native notice'])->get($path)->assertOk()->assertSee('native notice');
        $this->foreign->exec("UPDATE action_text_rich_texts SET body='flash foreign fragment'");
        $this->withSession(['notice' => 'second notice'])->get($path)->assertOk()->assertSee('flash foreign fragment')->assertSee('second notice');

        config(['campfire.response_cache_mb' => 0]);
        $this->get($path)->assertOk()->assertSee('flash foreign fragment');
        $this->foreign->exec("UPDATE action_text_rich_texts SET body='disabled foreign fragment'");
        $this->get($path)->assertOk()->assertSee('disabled foreign fragment')->assertDontSee('flash foreign fragment');
        $this->assertSame($message->getRawOriginal('updated_at'), $message->fresh()->getRawOriginal('updated_at'));
    }

    public function test_native_boost_fragments_refresh_unversioned_content_and_booster(): void
    {
        $message = app(MessageWriter::class)->create($this->room, $this->user, ['body' => 'message'], false);
        $booster = User::create(['name' => 'Original booster', 'role' => 0, 'status' => 0]);
        $boost = Boost::create(['message_id' => $message->id, 'booster_id' => $booster->id, 'content' => 'old boost']);
        $path = '/messages/'.$message->id.'/boosts';
        $this->get($path)->assertOk()->assertSee('Original booster boosted old boost');
        $this->foreign->exec("UPDATE boosts SET content='foreign boost'; UPDATE users SET name='Foreign booster' WHERE id=".$booster->id);
        $events = tempnam(sys_get_temp_dir(), 'campfire-fragment-events-');
        config(['campfire.events' => $events]);
        try {
            $broadcast = app(ChatEvents::class)->created($message);
            $this->assertStringContainsString('Foreign booster boosted foreign boost', $broadcast);
            $this->assertStringNotContainsString('old boost', $broadcast);
        } finally {
            unlink($events);
        }
        $this->get($path)->assertOk()->assertSee('Foreign booster boosted foreign boost')->assertDontSee('old boost');
        $this->assertSame($boost->getRawOriginal('updated_at'), $boost->fresh()->getRawOriginal('updated_at'));
    }

    public function test_disabled_cache_does_not_keep_native_fragments_after_foreign_writes(): void
    {
        config(['campfire.response_cache_mb' => 0]);
        app(MessageWriter::class)->create($this->room, $this->user, ['body' => 'disabled original body'], false);
        $path = '/rooms/'.$this->room->id;
        $this->get($path)->assertOk()->assertSee('disabled original body');
        $this->foreign->exec("UPDATE action_text_rich_texts SET body='disabled fresh body'");
        $this->get($path)->assertOk()->assertSee('disabled fresh body')->assertDontSee('disabled original body');
    }

    public function test_native_fragments_do_not_reuse_or_admit_uncommitted_presentations(): void
    {
        app(MessageWriter::class)->create($this->room, $this->user, ['body' => 'committed body'], false);
        $path = '/rooms/'.$this->room->id;
        $headers = ['If-None-Match' => 'unmatched'];
        $this->get($path, $headers)->assertOk()->assertSee('committed body');
        DB::beginTransaction();
        try {
            DB::table('action_text_rich_texts')->update(['body' => 'transaction draft']);
            $this->get($path, $headers)->assertOk()->assertSee('transaction draft')->assertDontSee('committed body');
        } finally {
            DB::rollBack();
        }
        $this->get($path, $headers)->assertOk()->assertSee('committed body')->assertDontSee('transaction draft');
    }

    public function test_detached_fragments_with_no_request_epoch_cannot_poison_the_current_generation(): void
    {
        $message = app(MessageWriter::class)->create($this->room, $this->user, ['body' => 'detached original'], false);
        $message->load(['creator', 'room', 'richText', 'boosts.booster', 'attachment.blob.variantRecords']);
        $this->foreign->exec("UPDATE action_text_rich_texts SET body='detached fresh'");
        request()->attributes->remove('campfire.response_epoch');
        $this->assertStringContainsString('detached original', app(MessageFragments::class)->render([$message]));
        $this->get('/rooms/'.$this->room->id, ['If-None-Match' => 'unmatched'])->assertOk()->assertSee('detached fresh')->assertDontSee('detached original');
    }

    public function test_native_fragment_reuse_is_origin_scoped_and_rejects_mid_render_commits(): void
    {
        app(MessageWriter::class)->create($this->room, $this->user, ['body' => 'message'], false);
        $renders = 0;
        View::composer('messages.message', function () use (&$renders): void {
            $renders++;
        });
        $path = '/rooms/'.$this->room->id;
        $headers = ['If-None-Match' => 'unmatched'];
        $this->get('https://first.example'.$path, $headers)->assertOk()->assertSee('https://first.example'.$path, false);
        $this->get('https://first.example'.$path, $headers)->assertOk();
        $this->assertSame(1, $renders);
        $this->get('https://second.example'.$path, $headers)->assertOk()->assertSee('https://second.example'.$path, false)->assertDontSee('https://first.example'.$path, false);
        $this->assertSame(2, $renders);
        app(ResponseCache::class)->clear();
        $changed = false;
        View::composer('messages.message', function () use (&$changed): void {
            if (! $changed) {
                $changed = true;
                $this->foreign->exec("UPDATE users SET name='Committed during fragment render'");
            }
        });
        $this->get($path, $headers)->assertOk();
        $this->get($path, $headers)->assertOk()->assertSee('Committed during fragment render');
        $this->assertSame(4, $renders);
    }
}
