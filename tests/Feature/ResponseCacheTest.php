<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
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
            if (! $changed && str_contains($query->sql, '"users"') && str_contains($query->sql, 'select')) {
                $changed = true;
                $this->foreign->exec("UPDATE users SET name='After auth'");
            }
        });
        $this->get('/rooms/'.$this->room->id)->assertOk();
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
}
