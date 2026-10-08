<?php

namespace App\Support;

use App\Models\Boost;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class MessageFragments
{
    public const VERSION = 'presentation-v5';

    public function render(iterable $messages): string
    {
        $messages = new EloquentCollection(Collection::make($messages)->values()->all());
        $messages->loadMissing(['creator', 'room', 'boosts.booster']);
        $keys = $messages->map($this->messageKey(...))->all();
        $epoch = $this->epoch();
        $cache = app(ResponseCache::class);
        $cached = $epoch === null ? array_fill_keys($keys, null) : $cache->many($keys, $epoch);
        $missing = $messages->filter(fn (Message $message, int $i) => ! is_array($cached[$keys[$i]]));
        if ($missing->isNotEmpty()) {
            $missing->loadMissing(['creator', 'richText', 'attachment.blob.variantRecords', 'boosts.booster', 'room']);
            foreach ($missing as $i => $message) {
                $entry = ['html' => view('messages.message', ['message' => $message])->render()];
                if ($epoch !== null) {
                    $cache->put($keys[$i], $epoch, $entry);
                }
                $cached[$keys[$i]] = $entry;
            }
        }

        return implode('', array_map(fn (string $key) => $cached[$key]['html'], $keys));
    }

    public function boostHtml(Boost $boost): string
    {
        $boost->loadMissing('booster');
        $key = $this->boostKey($boost);
        $epoch = $this->epoch();
        $cache = app(ResponseCache::class);
        $entry = $epoch === null ? null : $cache->get($key, $epoch);
        if (! is_array($entry)) {
            $entry = ['html' => view('boosts.boost-body', ['boost' => $boost])->render()];
            if ($epoch !== null) {
                $cache->put($key, $epoch, $entry);
            }
        }

        return $entry['html'];
    }

    public function messageKey(Message $message): string
    {
        return 'message:'.hash('sha256', json_encode([
            self::VERSION, url('/'), $message->id,
            $message->getRawOriginal('updated_at'), $message->creator?->getRawOriginal('updated_at'),
            $message->room?->displayName(),
            $message->boosts->map($this->boostKey(...))->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function boostKey(Boost $boost): string
    {
        return implode(':', ['boost', self::VERSION, url('/'), $boost->id,
            $boost->getRawOriginal('updated_at'), $boost->booster?->getRawOriginal('updated_at')]);
    }

    private function epoch(): ?int
    {
        // Detached models have no safe snapshot.
        $epoch = request()->attributes->get('campfire.response_epoch');

        return is_int($epoch) ? $epoch : null;
    }
}
