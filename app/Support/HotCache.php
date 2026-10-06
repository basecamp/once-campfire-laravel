<?php

namespace App\Support;

/**
 * Tiny APCu helpers for request hot paths. Falls back to computing when APCu is absent
 * (tests / CLI). Values are request-shared across Octane workers via APCu shm.
 */
final class HotCache
{
    public static function enabled(): bool
    {
        return extension_loaded('apcu') && apcu_enabled();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! self::enabled()) {
            return $default;
        }
        $ok = false;
        $value = apcu_fetch($key, $ok);

        return $ok ? $value : $default;
    }

    public static function put(string $key, mixed $value, int $ttl = 3600): void
    {
        if (self::enabled()) {
            apcu_store($key, $value, $ttl);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        if (self::enabled()) {
            $ok = false;
            $value = apcu_fetch($key, $ok);
            if ($ok) {
                return $value;
            }
        }
        $value = $callback();
        self::put($key, $value, $ttl);

        return $value;
    }
}
