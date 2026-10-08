<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RailsCrypto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FetchMetadata
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $this->authenticatedBot($request)
            || ($request->isMethod('PUT') && preg_match('~^rails/active_storage/disk/([^/]+)$~', $request->path(), $disk)
                && is_array(app(RailsCrypto::class)->appVerify($disk[1], 'blob_token')))) {
            return $next($request);
        }
        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== $request->getSchemeAndHttpHost()) {
            throw new HttpException(422, 'Invalid request origin');
        }
        $site = $request->header('Sec-Fetch-Site');
        if (in_array($site, ['same-origin', 'same-site'], true)
            || ($site === null && ! $request->isSecure() && ! config('campfire.force_ssl'))) {
            return $next($request);
        }

        throw new HttpException(422, 'Invalid request origin');
    }

    private function authenticatedBot(Request $request): bool
    {
        if (! preg_match('~^rooms/\d+/([^/]+)/messages(?:/\d+(?:/boosts(?:/\d+)?)?)?$~', $request->path(), $match)) {
            return false;
        }
        $parts = explode('-', trim($match[1]), 2);

        return count($parts) === 2 && User::active()->where('role', 2)->where('id', $parts[0])->where('bot_token', $parts[1])->exists();
    }
}
