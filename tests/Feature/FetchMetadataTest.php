<?php

namespace Tests\Feature;

use App\Http\Middleware\FetchMetadata;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class FetchMetadataTest extends TestCase
{
    public function test_method_origin_site_and_tls_matrix_ignores_old_tokens(): void
    {
        foreach (['GET', 'HEAD', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'] as $method) {
            foreach ([
                [[], true],
                [['Sec-Fetch-Site' => 'same-origin'], true],
                [['Sec-Fetch-Site' => 'same-site'], true],
                [['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'http://campfire.test'], true],
                [['Sec-Fetch-Site' => 'cross-site'], false],
                [['Sec-Fetch-Site' => 'none'], false],
                [['Sec-Fetch-Site' => ''], false],
                [['Sec-Fetch-Site' => 'SAME-ORIGIN'], false],
                [['Sec-Fetch-Site' => 'bogus'], false],
                [['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'null'], false],
                [['Sec-Fetch-Site' => 'same-site', 'Origin' => 'http://other.test'], false],
                [['Sec-Fetch-Site' => 'same-origin', 'Origin' => ''], false],
            ] as [$headers, $allowed]) {
                $request = Request::create('http://campfire.test/probe', $method, ['authenticity_token' => 'old-token', '_token' => 'old-token']);
                foreach ($headers as $name => $value) {
                    $request->headers->set($name, $value);
                }
                $accepted = $this->accepted($request);
                $this->assertSame(in_array($method, ['GET', 'HEAD'], true) || $allowed, $accepted, $method.' '.json_encode($headers));
            }
        }
        $secure = Request::create('https://campfire.test/probe', 'POST');
        $this->assertFalse($this->accepted($secure));
        $secure->headers->set('Sec-Fetch-Site', 'same-origin');
        $secure->headers->set('Origin', 'https://campfire.test');
        $this->assertTrue($this->accepted($secure));
        config(['campfire.force_ssl' => true]);
        $this->assertFalse($this->accepted(Request::create('http://campfire.test/probe', 'POST')));
        $secure->headers->set('Sec-Fetch-Site', 'same-site');
        $this->assertTrue($this->accepted($secure));
    }

    public function test_only_trusted_proxy_headers_define_the_effective_origin_and_tls(): void
    {
        $previous = Request::getTrustedProxies();
        $headers = Request::getTrustedHeaderSet();
        try {
            $request = Request::create('http://internal/probe', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
            $request->headers->set('X-Forwarded-Proto', 'https');
            $request->headers->set('X-Forwarded-Host', 'campfire.test');
            $this->assertTrue($this->accepted($request), 'Untrusted forwarded TLS is ignored.');
            Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST);
            $this->assertFalse($this->accepted($request), 'Trusted HTTPS requires fetch metadata.');
            $request->headers->set('Sec-Fetch-Site', 'same-origin');
            $request->headers->set('Origin', 'https://campfire.test');
            $this->assertTrue($this->accepted($request));
            $request->headers->set('Origin', 'http://internal');
            $this->assertFalse($this->accepted($request));
        } finally {
            Request::setTrustedProxies($previous, $headers);
        }
    }

    private function accepted(Request $request): bool
    {
        try {
            (new FetchMetadata)->handle($request, fn () => response('accepted'));

            return true;
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());

            return false;
        }
    }
}
