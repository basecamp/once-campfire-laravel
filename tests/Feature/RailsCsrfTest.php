<?php

namespace Tests\Feature;

use App\Http\Middleware\RailsCsrf;
use App\Support\RailsCrypto;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

final class RailsCsrfTest extends TestCase
{
    public function test_all_rails_csrf_validity_vectors(): void
    {
        $v = json_decode(file_get_contents(base_path('compat/rails_compat.json')), true)['csrf'];
        $guard = new RailsCsrf(app(), app('encrypter'));
        $method = new \ReflectionMethod($guard, 'tokensMatch');
        foreach ($v['validity'] as $row) {
            $r = Request::create($row['path'], $row['method'], ['_token' => $row['token']]);
            $session = new Store('test', new ArraySessionHandler(120));
            $session->start();
            $session->put('_token', $v['session_token']);
            $r->setLaravelSession($session);
            $this->assertSame($row['expected'], $method->invoke($guard, $r), $row['case']);
        }
    }

    public function test_distinct_numeric_string_return_targets_reissue_the_changed_rails_payload(): void
    {
        $crypto = app(RailsCrypto::class);
        $payload = ['_csrf_token' => base64_encode(str_repeat('x', 32)), 'session_id' => 'payload-fixture', 'return_to_after_authenticating' => '1e3'];
        $request = Request::create('/session/new', 'GET', [], ['_campfire_session' => $crypto->encryptCookie('_campfire_session', $payload)]);
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $guard = new RailsCsrf(app(), app('encrypter'));
        $response = $guard->handle($request, function ($request) {
            $request->session()->put('return_to', '1000');

            return response('changed target');
        });
        $cookies = array_values(array_filter($response->headers->getCookies(), fn ($cookie) => $cookie->getName() === '_campfire_session'));
        $this->assertCount(1, $cookies);
        $after = $crypto->decryptCookie('_campfire_session', $cookies[0]->getValue());
        $this->assertSame('1000', $after['return_to_after_authenticating']);
        $this->assertSame($payload['_csrf_token'], $after['_csrf_token']);
    }
}
