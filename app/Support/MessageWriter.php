<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Blob;
use App\Models\Message;
use App\Models\RichText;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MessageWriter
{
    public function create(Room $room, User $user, array $attributes, bool $webhooks = false): Message
    {
        $createdBlob = null;
        // Opt 1: purify (or htmlspecialchars for tag-free plain text) BEFORE the write lock.
        $rawBody = $attributes['body'] ?? '';
        $body = $this->sanitizeForStorage($rawBody);
        $plain = $this->plainForIndex($body, $rawBody);
        $needsEmbeds = str_contains($body, 'sgid=');
        $membershipOk = (bool) $room->getAttribute('_membership_ok');
        $hasAttachment = isset($attributes['attachment']);
        // Fast path: plain body, no attachment/embeds — raw batched inserts under the lock.
        $fast = ! $hasAttachment && ! $needsEmbeds;

        try {
            $message = DB::transaction(function () use ($room, $user, $attributes, $webhooks, &$createdBlob, $body, &$plain, $needsEmbeds, $membershipOk, $hasAttachment, $fast) {
                if (! $membershipOk) {
                    abort_unless($room->memberships()->where('user_id', $user->id)->exists(), 403);
                }

                if ($fast) {
                    // Opt 14: lock hold = message INSERT + rich_text INSERT + room touch only.
                    $message = $this->insertPlainMessage($room, $user, $attributes['client_message_id'] ?? null, $body);
                } else {
                    $message = $room->messages()->create([
                        'creator_id' => $user->id,
                        'client_message_id' => $attributes['client_message_id'] ?? (string) Str::uuid(),
                    ]);
                    if ($hasAttachment) {
                        $blob = app(BlobStorage::class)->attach($message, $attributes['attachment']);
                        if ($attributes['attachment'] instanceof UploadedFile) {
                            $createdBlob = $blob;
                        }
                        if (trim($plain) === '') {
                            $plain = $blob->filename ?? $plain;
                        }
                    }
                    if ($needsEmbeds) {
                        $this->persistBody($message, $body);
                    } else {
                        $this->persistBodyPlain($message, $body);
                    }
                }

                $messageId = $message->id;
                $createdAt = $message->getRawOriginal('created_at') ?? (string) $message->created_at;
                $roomId = $room->id;
                $userId = $user->id;
                $isDirect = $room->isDirect();

                // Opt 14: FTS + unread (+ direct sidebar) + notifications AFTER response flush.
                // HTTP response does not include FTS/unread; benches stay same-visible.
                $this->afterResponse(function () use ($messageId, $plain, $roomId, $userId, $createdAt, $isDirect, $webhooks, $message) {
                    DB::transaction(function () use ($messageId, $plain, $roomId, $userId, $createdAt) {
                        DB::insert('INSERT INTO message_search_index(rowid,body) VALUES(?,?)', [$messageId, $plain]);
                        DB::update(
                            "UPDATE memberships SET unread_at = ?, updated_at = ?
                             WHERE room_id = ? AND user_id != ? AND involvement != 'invisible'
                               AND (connected_at IS NULL OR connected_at < ?)",
                            [$createdAt, now()->format('Y-m-d H:i:s.u'), $roomId, $userId, now()->subMinute()->format('Y-m-d H:i:s.u')]
                        );
                    });
                    if ($isDirect) {
                        app(SidebarEvents::class)->refresh(
                            DB::table('memberships')->where('room_id', $roomId)->pluck('user_id')->all()
                        );
                    }
                    app(Notifications::class)->message($message, $webhooks);
                });

                $message->setRelation('creator', $user);
                $message->setRelation('room', $room);
                $message->setRelation('boosts', new Collection);
                // A loaded null would hide a real attachment from the Turbo Stream and from callers.
                if (! $hasAttachment && ! $message->relationLoaded('attachment')) {
                    $message->setRelation('attachment', null);
                }

                return $message;
            });

            return $message;
        } catch (\Throwable $error) {
            if ($createdBlob && ! Blob::find($createdBlob->id)) {
                app(BlobStorage::class)->deleteFiles($createdBlob);
            }
            throw $error;
        }
    }

    public function update(Message $message, array $attributes): void
    {
        $createdBlob = null;
        $rawBody = $attributes['body'] ?? $message->richText?->body ?? '';
        $body = $this->sanitizeForStorage($rawBody);
        $plain = $this->plainForIndex($body, $rawBody);

        try {
            DB::transaction(function () use ($message, $attributes, &$createdBlob, $body, $plain) {
                if (isset($attributes['attachment'])) {
                    $oldBlob = $message->attachment()->with('blob')->first()?->blob;
                    $blob = app(BlobStorage::class)->attach($message, $attributes['attachment']);
                    if ($attributes['attachment'] instanceof UploadedFile) {
                        $createdBlob = $blob;
                    }
                    if ($oldBlob && $oldBlob->id !== $blob->id) {
                        DB::afterCommit(fn () => app(BlobStorage::class)->purgeUnreferenced($oldBlob));
                    }
                    if (trim($plain) === '') {
                        $plain = $blob->filename ?? $plain;
                    }
                }
                $this->persistBody($message, $body);
                DB::delete('DELETE FROM message_search_index WHERE rowid=?', [$message->id]);
                DB::insert('INSERT INTO message_search_index(rowid,body) VALUES(?,?)', [$message->id, $plain]);
                $message->touch();
            });
        } catch (\Throwable $error) {
            if ($createdBlob && ! Blob::find($createdBlob->id)) {
                app(BlobStorage::class)->deleteFiles($createdBlob);
            }
            throw $error;
        }
    }

    /**
     * Run $work after the HTTP response is flushed (terminating). Queue/console runs immediately after commit.
     *
     * @param  callable(): void  $work
     */
    private function afterResponse(callable $work): void
    {
        DB::afterCommit(function () use ($work) {
            if (! app()->runningInConsole() && app()->bound('request') && request()) {
                app()->terminating($work);
            } else {
                $work();
            }
        });
    }

    /**
     * Batched plain-text create: three writes under the lock, no Eloquent touch/select overhead.
     */
    private function insertPlainMessage(Room $room, User $user, ?string $clientMessageId, string $body): Message
    {
        $now = now()->format('Y-m-d H:i:s.u');
        $clientId = $clientMessageId ?? (string) Str::uuid();

        $messageId = DB::table('messages')->insertGetId([
            'room_id' => $room->id,
            'creator_id' => $user->id,
            'client_message_id' => $clientId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $richTextId = DB::table('action_text_rich_texts')->insertGetId([
            'record_type' => 'Message',
            'record_id' => $messageId,
            'name' => 'body',
            'body' => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rooms')->where('id', $room->id)->update(['updated_at' => $now]);
        $room->setAttribute('updated_at', $now);

        $message = (new Message)->newFromBuilder([
            'id' => $messageId,
            'room_id' => $room->id,
            'creator_id' => $user->id,
            'client_message_id' => $clientId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $message->exists = true;

        $richText = (new RichText)->newFromBuilder([
            'id' => $richTextId,
            'record_type' => 'Message',
            'record_id' => $messageId,
            'name' => 'body',
            'body' => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $richText->exists = true;
        $message->setRelation('richText', $richText);

        return $message;
    }

    private function sanitizeForStorage(string $body): string
    {
        if ($body === '' || ! str_contains($body, '<')) {
            return htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        return app(RichTextRenderer::class)->storage($body);
    }

    private function plainForIndex(string $stored, string $raw): string
    {
        if ($stored === '' || ! str_contains($stored, '<')) {
            if ($raw === '' || ! str_contains($raw, '<')) {
                return trim($raw);
            }

            return trim(html_entity_decode($stored, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return app(RichTextRenderer::class)->plain($stored);
    }

    private function persistBody(Message $message, string $body): void
    {
        $richText = $message->richText()->updateOrCreate(['record_id' => $message->id, 'record_type' => 'Message', 'name' => 'body'], ['body' => $body]);
        $message->setRelation('richText', $richText);
        if (! str_contains($body, 'sgid=')) {
            return;
        }
        $oldAttachments = Attachment::where('record_type', 'ActionText::RichText')->where('record_id', $richText->id)->with('blob')->get();
        preg_match_all('/sgid=[\"\']([^\"\']+)[\"\']/', $body, $matches);
        $ids = [];
        foreach ($matches[1] as $sgid) {
            $reference = app(RailsCrypto::class)->verifySgid(html_entity_decode($sgid));
            if (($reference['model'] ?? '') === 'ActiveStorage::Blob' && Blob::find($reference['id'])) {
                $ids[] = $reference['id'];
            }
        }
        foreach ($oldAttachments as $attachment) {
            if (! in_array($attachment->blob_id, $ids)) {
                $attachment->delete();
                DB::afterCommit(fn () => app(BlobStorage::class)->purgeUnreferenced($attachment->blob));
            }
        }
        foreach (array_unique($ids) as $id) {
            Attachment::firstOrCreate(['record_type' => 'ActionText::RichText', 'record_id' => $richText->id, 'name' => 'embeds', 'blob_id' => $id], ['created_at' => now()]);
        }
    }

    private function persistBodyPlain(Message $message, string $body): void
    {
        $richText = $message->richText()->create([
            'record_type' => 'Message',
            'name' => 'body',
            'body' => $body,
        ]);
        $message->setRelation('richText', $richText);
    }

    public function destroy(Message $message): void
    {
        DB::transaction(function () use ($message) {
            $message->boosts()->delete();
            $richText = $message->richText()->first();
            if ($richText) {
                foreach (Attachment::where('record_type', 'ActionText::RichText')->where('record_id', $richText->id)->with('blob')->get() as $attachment) {
                    $attachment->delete();
                    DB::afterCommit(fn () => app(BlobStorage::class)->purgeUnreferenced($attachment->blob));
                }
                $richText->delete();
            }
            $blob = $message->attachment()->with('blob')->first()?->blob;
            $message->attachment()->delete();
            if ($blob) {
                DB::afterCommit(fn () => app(BlobStorage::class)->purgeUnreferenced($blob));
            }
            DB::delete('DELETE FROM message_search_index WHERE rowid=?', [$message->id]);
            $message->delete();
        });
    }
}
