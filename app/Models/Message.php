<?php

namespace App\Models;

use App\Support\RichTextRenderer;
use Illuminate\Support\Facades\DB;

final class Message extends Record
{
    /** Relations needed to render a message partial or its JSON. */
    public const PRESENTATION = ['creator', 'richText', 'attachment.blob.variantRecords', 'boosts.booster', 'room'];

    /** Like Rails' `belongs_to :room, touch: true`: every message or boost change bumps the room's version. */
    protected $touches = ['room'];

    private static ?array $sounds = null;

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function richText()
    {
        return $this->hasOne(RichText::class, 'record_id')->where('record_type', 'Message')->where('name', 'body');
    }

    public function boosts()
    {
        return $this->hasMany(Boost::class);
    }

    public function attachment()
    {
        return $this->hasOne(Attachment::class, 'record_id')->where('record_type', 'Message')->where('name', 'attachment');
    }

    public function scopePresentation($q)
    {
        return $q->with(self::PRESENTATION);
    }

    public static function searchFor(User $user, string $query)
    {
        $terms = implode(' ', array_map(fn ($word) => '"'.str_replace('"', '""', $word).'"', preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY)));
        if ($terms === '') {
            return collect();
        }
        $probe = DB::select('SELECT m.id, ms.user_id IS NOT NULL AS reachable FROM message_search_index idx JOIN messages m ON m.id=idx.rowid LEFT JOIN memberships ms ON ms.room_id=m.room_id AND ms.user_id=? WHERE idx.body MATCH ? ORDER BY idx.rowid DESC LIMIT 1000', [$user->id, $terms]);
        $ids = collect($probe)->filter(fn ($row) => $row->reachable)->take(100)->pluck('id')->all();
        if (count($ids) < 100 && count($probe) === 1000) {
            $ids = collect(DB::select('SELECT m.id FROM messages m JOIN message_search_index idx ON idx.rowid=m.id JOIN memberships ms ON ms.room_id=m.room_id WHERE ms.user_id=? AND idx.body MATCH ? ORDER BY m.id DESC LIMIT 100', [$user->id, $terms]))->pluck('id')->all();
        }

        return self::query()->presentation()->whereIn('id', $ids)
            ->whereIn('room_id', $user->rooms()->select('rooms.id'))->orderBy('id')->get();
    }

    public function plainText(): string
    {
        $plain = app(RichTextRenderer::class)->plain($this->richText?->body ?? '');

        return trim($plain) !== '' ? $plain : ($this->attachment?->blob?->filename ?? '');
    }

    /**
     * The sound for a `/play <name>` message, if any.
     */
    public function sound(): ?array
    {
        $body = $this->richText?->body ?? '';
        if (! str_contains($body, 'play') && ! str_contains($body, '<action-text-attachment')) {
            return null;
        }
        self::$sounds ??= json_decode(file_get_contents(resource_path('sounds.json')), true);

        return preg_match('/^\/play (\w+)$/', $this->plainText(), $match) ? self::$sounds[$match[1]] ?? null : null;
    }
}
