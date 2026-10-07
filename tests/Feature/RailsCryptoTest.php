<?php

namespace Tests\Feature;

use App\Support\RailsCrypto;
use Carbon\Carbon;
use Tests\TestCase;

final class RailsCryptoTest extends TestCase
{
    private array $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = json_decode(file_get_contents(base_path('compat/rails_compat.json')), true);
        config(['campfire.secret' => $this->v['secret_key_base']]);
        Carbon::setTestNow($this->v['now']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reads_and_generates_actual_rails_cookie_goldens(): void
    {
        $c = app(RailsCrypto::class);
        foreach ($this->v['signed_cookies']['generate'] as $v) {
            $this->assertSame($v['value'], $c->verifyCookie($v['name'], $v['raw']));
            $this->assertSame($v['raw'], $c->signCookie($v['name'], $v['value'], $v['expires_at']));
        }
        foreach ($this->v['encrypted_cookies']['generate'] as $v) {
            $this->assertSame($v['value'], $c->decryptCookie($v['name'], $v['raw']));
        }
    }

    public function test_user_avatar_signed_ids_match_rails(): void
    {
        $c = app(RailsCrypto::class);
        foreach ($this->v['signed_ids']['generate'] as $v) {
            if ($v['expires_at'] === null) {
                $this->assertSame($v['signed_id'], $c->signedId($v['id'], $v['model'], $v['purpose']));
                $this->assertSame($v['id'], $c->verifyId($v['signed_id'], $v['model'], $v['purpose']));
            }
        }
    }

    public function test_turbo_stream_digest_matches_rails(): void
    {
        $c = app(RailsCrypto::class);
        $v = $this->v['turbo_stream_names']['generate'][0];
        $this->assertSame($v['signed'], $c->stream(1, 'Rooms::Open'));
        $this->assertSame(1, $c->verifyStream($v['signed']));
        $this->assertNull($c->verifyStream(substr($v['signed'], 0, -1).'0'));
    }

    public function test_signed_global_ids_match_rails(): void
    {
        $c = app(RailsCrypto::class);
        foreach ($this->v['sgids']['generate'] as $v) {
            if ($v['expires_at'] === null && str_contains($v['gid'], '/User/')) {
                $id = (int) basename($v['gid']);
                $this->assertSame($v['sgid'], $c->sgid($id));
                $this->assertSame(['model' => 'User', 'id' => $id], $c->verifySgid($v['sgid']));
            }
        }
    }

    public function test_purpose_and_signature_boundaries(): void
    {
        $c = app(RailsCrypto::class);
        $signed = $c->signCookie('session_token', 'login');
        $this->assertNull($c->verifyCookie('different_cookie', $signed));
        $this->assertNull($c->verifyCookie('session_token', substr($signed, 0, -1).'z'));
        $this->assertNull($c->verifyId($c->signedId(1, 'User', 'avatar'), 'User', 'transfer'));
        $encrypted = $c->encryptCookie('_campfire_session', ['a' => 1]);
        $this->assertSame(['a' => 1], $c->decryptCookie('_campfire_session', $encrypted));
        $this->assertNull($c->decryptCookie('wrong', $encrypted));
    }

    public function test_all_cookie_and_signed_id_positive_and_negative_oracles(): void
    {
        $c = app(RailsCrypto::class);
        foreach (['signed_cookies', 'encrypted_cookies', 'signed_ids'] as $key) {
            foreach ($this->v[$key]['verify'] as $row) {
                Carbon::setTestNow($row['now'] ?? $this->v['now']);
                $actual = match ($key) {
                    'signed_cookies' => $c->verifyCookie($row['name'], $row['raw']),'encrypted_cookies' => $c->decryptCookie($row['name'], $row['raw']),'signed_ids' => $c->verifyId($row['signed_id'], $row['model'], $row['purpose'])
                };
                $expected = $row['expected'];
                if ($key === 'signed_ids' && is_string($expected)) {
                    $expected = (int) $expected;
                }
                $this->assertSame($expected, $actual, $row['case']);
            }
        }
    }

    public function test_active_storage_specific_verifier_goldens(): void
    {
        $c = app(RailsCrypto::class);
        foreach ($this->v['app_verifiers']['generate'] as $row) {
            if ($row['name'] !== 'ActiveStorage') {
                continue;
            }
            $value = json_decode($row['data_json'], true);
            $this->assertSame($row['message'], $c->appSign($value, $row['purpose'], $row['expires_at']));
            $this->assertSame($value, $c->appVerify($row['message'], $row['purpose']));
        }
    }

    public function test_cached_keys_track_secret_rotation_salt_and_output_length(): void
    {
        $crypto = app(RailsCrypto::class);
        config(['campfire.secret' => 'first-local-fixture-secret']);
        $first = $crypto->key('signed cookie');
        $this->assertSame(hash_pbkdf2('sha256', 'first-local-fixture-secret', 'signed cookie', 1000, 64, true), $first);
        $this->assertSame($first, $crypto->key('signed cookie'));
        $this->assertSame(hash_pbkdf2('sha256', 'first-local-fixture-secret', 'signed cookie', 1000, 32, true), $crypto->key('signed cookie', 32));
        $this->assertNotSame($first, $crypto->key('active_record/signed_id'));
        $oldCookie = $crypto->signCookie('session_token', 'fixture');
        config(['campfire.secret' => 'rotated-local-fixture-secret']);
        $this->assertNotSame($first, $crypto->key('signed cookie'));
        $this->assertNull($crypto->verifyCookie('session_token', $oldCookie));
        $newCookie = $crypto->signCookie('session_token', 'fixture');
        $this->assertSame('fixture', $crypto->verifyCookie('session_token', $newCookie));
        config(['campfire.secret' => 'first-local-fixture-secret']);
        $this->assertSame($first, $crypto->key('signed cookie'));
        $this->assertNull($crypto->verifyCookie('session_token', $newCookie));
        $this->assertSame('fixture', $crypto->verifyCookie('session_token', $oldCookie));
    }
}
