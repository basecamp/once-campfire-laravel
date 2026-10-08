<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Blob;
use App\Models\Message;
use App\Models\RichText;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MessageWriter
{
    public function create(Room $room, User $user, array $attributes, bool $webhooks = false): Message
    {
        // Sanitizing and an upload's file IO, analysis and variants happen before the write lock.
        // Membership and attachment plain text resolve current rows inside the transaction;
        // ordinary text can be prepared here.
        $renderer = app(RichTextRenderer::class);
        $body = $renderer->storage($attributes['body'] ?? '');
        $plain = preg_match('~<action-text-attachment\b~i', $body) === 1 ? null : $renderer->plain($body);
        $hasEmbeds = preg_match('/sgid=[\"\']/', $body) === 1;
        $blob = null;
        if (isset($attributes['attachment'])) {
            $blob = app(BlobStorage::class)->prepare($attributes['attachment']);
        }
        try {
            return DB::transaction(function () use ($room, $user, $attributes, $webhooks, $blob, $renderer, $body, $plain, $hasEmbeds) {
                abort_unless(DB::table('memberships')->where('room_id', $room->id)->where('user_id', $user->id)->exists(), 403);
                $plain ??= $renderer->plain($body);
                // Plain inserts with one timestamp: the rows Eloquent's create() and Message::$touches
                // (Rails' belongs_to :room, touch: true) write, without model events or a room reload.
                $now = (new Message)->freshTimestampString();
                $row = ['room_id' => $room->id, 'creator_id' => $user->id, 'client_message_id' => $attributes['client_message_id'] ?? (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now];
                $message = (new Message)->newFromBuilder($row + ['id' => DB::table('messages')->insertGetId($row)])->setRelation('room', $room);
                DB::table('rooms')->where('id', $room->id)->update(['updated_at' => $now]);
                if ($blob) {
                    app(BlobStorage::class)->attach($message, $blob);
                }
                // A new message has no rich text, embeds or index row yet: insert directly.
                $row = ['record_id' => $message->id, 'record_type' => 'Message', 'name' => 'body', 'body' => $body, 'created_at' => $now, 'updated_at' => $now];
                $richText = (new RichText)->newFromBuilder($row + ['id' => DB::table('action_text_rich_texts')->insertGetId($row)]);
                $message->setRelation('richText', $richText);
                if ($hasEmbeds) {
                    $this->embeds($richText, $body);
                }
                if ($blob?->filename && trim($plain) === '') {
                    $plain = $blob->filename;
                }
                DB::insert('INSERT INTO message_search_index(rowid,body) VALUES(?,?)', [$message->id, $plain]);
                // Shared rooms keep their first unread timestamp; directs refresh sidebar recency.
                // Matches the unread policy adopted in Rails PR #336.
                $unread = DB::table('memberships')->where('room_id', $room->id)->where('user_id', '!=', $user->id)->where('involvement', '!=', 'invisible')->where(fn ($q) => $q->whereNull('connected_at')->orWhere('connected_at', '<', now()->subMinute()));
                if (! $room->isDirect()) {
                    $unread->whereNull('unread_at');
                }
                $unread->update(['unread_at' => $message->created_at, 'updated_at' => now()]);
                if ($room->type === 'Rooms::Direct') {
                    app(SidebarEvents::class)->refresh($room->users()->pluck('users.id')->all());
                }
                // DeliverMessageNotifications loads what it needs from the id.
                DB::afterCommit(fn () => app(Notifications::class)->message($message, $webhooks));

                return $message;
            });
        } catch (\Throwable $error) {
            $this->discardUpload($attributes, $blob);
            throw $error;
        }
    }

    public function update(Message $message, array $attributes): void
    {
        // As in create(): the upload's file work happens before the write lock.
        $blob = isset($attributes['attachment']) ? app(BlobStorage::class)->prepare($attributes['attachment']) : null;
        try {
            DB::transaction(function () use ($message, $attributes, $blob) {
                if ($blob) {
                    $oldBlob = $message->attachment()->with('blob')->first()?->blob;
                    app(BlobStorage::class)->attach($message, $blob);
                    if ($oldBlob && $oldBlob->id !== $blob->id) {
                        DB::afterCommit(fn () => app(BlobStorage::class)->purgeUnreferenced($oldBlob));
                    }
                }
                $this->body($message, $attributes['body'] ?? $message->richText?->body ?? '');
                $message->touch();
                $message->room->touch();
            });
        } catch (\Throwable $error) {
            $this->discardUpload($attributes, $blob);
            throw $error;
        }
    }

    /** A prepared upload whose row did not commit leaves no files behind. */
    private function discardUpload(array $attributes, ?Blob $blob): void
    {
        if ($blob && $attributes['attachment'] instanceof UploadedFile && ! Blob::where('key', $blob->key)->exists()) {
            app(BlobStorage::class)->deleteFiles($blob);
        }
    }

    /** Embedded blob attachments of a newly created rich text body (as body() records them). */
    private function embeds(RichText $richText, string $body): void
    {
        preg_match_all('/sgid=[\"\']([^\"\']+)[\"\']/', $body, $matches);
        $ids = [];
        foreach ($matches[1] as $sgid) {
            $reference = app(RailsCrypto::class)->verifySgid(html_entity_decode($sgid));
            if (($reference['model'] ?? '') === 'ActiveStorage::Blob' && Blob::find($reference['id'])) {
                $ids[] = $reference['id'];
            }
        }
        foreach (array_unique($ids) as $id) {
            Attachment::firstOrCreate(['record_type' => 'ActionText::RichText', 'record_id' => $richText->id, 'name' => 'embeds', 'blob_id' => $id], ['created_at' => now()]);
        }
    }

    private function body(Message $message, string $body): void
    {
        $body = app(RichTextRenderer::class)->storage($body);
        $richText = $message->richText()->updateOrCreate(['record_id' => $message->id, 'record_type' => 'Message', 'name' => 'body'], ['body' => $body]);
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
        $plain = app(RichTextRenderer::class)->plain($body);
        $filename = $message->attachment()->with('blob')->first()?->blob?->filename;
        if (trim($plain) === '' && $filename) {
            $plain = $filename;
        }
        DB::delete('DELETE FROM message_search_index WHERE rowid=?', [$message->id]);
        DB::insert('INSERT INTO message_search_index(rowid,body) VALUES(?,?)', [$message->id, $plain]);
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
            $message->room->touch();
        });
    }
}
