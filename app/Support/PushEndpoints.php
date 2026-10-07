<?php

namespace App\Support;

final class PushEndpoints
{
    public function resolve(string $endpoint): ?string
    {
        $u = parse_url($endpoint);
        if (! $u || ($u['scheme'] ?? '') !== 'https' || ($u['port'] ?? 443) !== 443 || isset($u['user']) || isset($u['pass'])) {
            return null;
        }
        $host = strtolower($u['host'] ?? '');
        $allowed = false;
        foreach (['jmt17.google.com', 'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', 'notify.windows.com'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                $allowed = true;
            }
        }
        if (! $allowed) {
            return null;
        }
        $ips = gethostbynamel($host);
        if (! $ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return $ips[0];
    }
}
