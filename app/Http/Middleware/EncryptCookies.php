<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt 3: skip re-encrypting response cookies whose plaintext is unchanged.
 * Reuses the client's existing ciphertext (drops Set-Cookie) when nothing changed.
 */
final class EncryptCookies extends Middleware
{
    protected $except = [
        'session_token',
        '_campfire_session',
    ];

    public function __construct(EncrypterContract $encrypter)
    {
        parent::__construct($encrypter);
    }

    public function handle($request, Closure $next)
    {
        $raw = [];
        foreach ($request->cookies->all() as $key => $value) {
            if (! $this->isDisabled($key)) {
                $raw[$key] = $value;
            }
        }
        $request->attributes->set('_encrypt_cookies_raw', $raw);

        return $this->encrypt($next($this->decrypt($request)), $request);
    }

    /**
     * @param  \Illuminate\Http\Request|Request  $request
     */
    protected function encrypt(Response $response, $request = null): Response
    {
        if ($request === null) {
            return parent::encrypt($response);
        }

        $raw = $request->attributes->get('_encrypt_cookies_raw', []);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($this->isDisabled($cookie->getName())) {
                continue;
            }

            $name = $cookie->getName();
            $newValue = $cookie->getValue();
            $oldPlain = $request->cookies->get($name);

            if (is_string($oldPlain) && is_string($newValue) && hash_equals($oldPlain, $newValue) && isset($raw[$name]) && is_string($raw[$name])) {
                // Unchanged: drop Set-Cookie so the browser keeps its existing sealed value.
                $response->headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());

                continue;
            }

            $response->headers->setCookie($this->duplicate(
                $cookie,
                $this->encrypter->encrypt(
                    CookieValuePrefix::create($name, $this->encrypter->getKey()).$newValue,
                    self::serialized($name)
                )
            ));
        }

        return $response;
    }
}
