<?php

namespace App\Support;

final class Broadcasts
{
    public function publish(string $stream, mixed $message): void
    {
        $this->publishMany([[$stream, $message]]);
    }

    /**
     * Append events to the cable outbox in a single locked write.
     *
     * Opt 6: during HTTP requests, buffer lines and flock/write after the response is flushed
     * (terminating), so POST latency does not include the events.log lock.
     *
     * @param  list<array{0: string, 1: mixed}>  $events
     */
    public function publishMany(array $events): void
    {
        $lines = '';
        foreach ($events as [$stream, $message]) {
            $lines .= json_encode(['stream' => $stream, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }
        if ($lines === '') {
            return;
        }

        if (! app()->runningInConsole() && app()->bound('request') && ($request = request())) {
            $pending = (string) $request->attributes->get('_broadcast_pending', '');
            $request->attributes->set('_broadcast_pending', $pending.$lines);
            if (! $request->attributes->get('_broadcast_scheduled')) {
                $request->attributes->set('_broadcast_scheduled', true);
                app()->terminating(function () use ($request) {
                    $buffered = (string) $request->attributes->get('_broadcast_pending', '');
                    $request->attributes->set('_broadcast_pending', '');
                    if ($buffered !== '') {
                        $this->writeLines($buffered);
                    }
                });
            }

            return;
        }

        $this->writeLines($lines);
    }

    private function writeLines(string $lines): void
    {
        $file = fopen(config('campfire.events'), 'ab');
        if (! $file) {
            throw new \RuntimeException('Cannot open broadcast outbox');
        }
        try {
            if (! flock($file, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock broadcast outbox');
            }
            for ($written = 0; $written < strlen($lines); $written += $count) {
                $count = fwrite($file, substr($lines, $written));
                if ($count === false || $count === 0) {
                    throw new \RuntimeException('Broadcast outbox write failed');
                }
            }
            fflush($file);
            flock($file, LOCK_UN);
        } finally {
            fclose($file);
        }
    }

    public function room(int $id, string $html): void
    {
        $this->publish('room_'.$id.'_messages', $html);
    }
}
