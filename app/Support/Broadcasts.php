<?php

namespace App\Support;

final class Broadcasts
{
    public function publish(string $stream, mixed $message): void
    {
        $line = json_encode(['stream' => $stream, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $file = fopen(config('campfire.events'), 'ab');
        if (! $file) {
            throw new \RuntimeException('Cannot open broadcast outbox');
        }
        try {
            if (! flock($file, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock broadcast outbox');
            }$written = 0;
            while ($written < strlen($line)) {
                $count = fwrite($file, substr($line, $written));
                if ($count === false || $count === 0) {
                    throw new \RuntimeException('Broadcast outbox write failed');
                }$written += $count;
            }fflush($file);
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
