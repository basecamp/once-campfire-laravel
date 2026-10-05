<?php

namespace App\Support;

use App\Models\Boost;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class MessageFragments
{
    public const VERSION = 'presentation-v3';

    public function render(iterable $messages): string
    {
        $messages = $messages instanceof Collection ? $messages : Collection::make($messages);
        $html = [];
        $misses = [];

        foreach ($messages as $message) {
            $cached = Cache::get($this->messageKey($message));
            if (is_string($cached)) {
                $html[$message->id] = $this->spliceCsrf($cached);
            } else {
                $misses[] = $message;
            }
        }

        if ($misses !== []) {
            (new EloquentCollection($misses))->loadMissing([
                'creator',
                'richText',
                'attachment.blob.variantRecords',
                'boosts.booster',
                'room',
            ]);
            foreach ($misses as $message) {
                $rendered = view('messages.message', ['message' => $message])->render();
                Cache::put($this->messageKey($message), $rendered, now()->addDays(30));
                $html[$message->id] = $this->spliceCsrf($rendered);
            }
        }

        $out = '';
        foreach ($messages as $message) {
            $out .= $html[$message->id] ?? '';
        }

        return $out;
    }

    public function boostHtml(Boost $boost): string
    {
        $key = $this->boostKey($boost);
        $cached = Cache::get($key);
        if (! is_string($cached)) {
            $cached = view('boosts.boost-body', ['boost' => $boost])->render();
            Cache::put($key, $cached, now()->addDays(30));
        }

        return $this->spliceCsrf($cached);
    }

    public function messageKey(Message $message): string
    {
        return 'message:'.$message->id.':'.$message->getRawOriginal('updated_at').':'.self::VERSION;
    }

    public function boostKey(Boost $boost): string
    {
        return 'boost:'.$boost->id.':'.$boost->getRawOriginal('updated_at');
    }

    public function spliceCsrf(string $html): string
    {
        $token = e(csrf_token());
        $html = preg_replace_callback(
            '/name="authenticity_token" value="[^"]*"/',
            static fn () => 'name="authenticity_token" value="'.$token.'"',
            $html
        );
        $html = preg_replace_callback(
            '/name="_token" value="[^"]*"/',
            static fn () => 'name="_token" value="'.$token.'"',
            $html
        );

        return $html ?? '';
    }
}
