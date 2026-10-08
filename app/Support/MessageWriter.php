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
        $createdBlob = null;
        // Sanitizing and plain-text extraction depend only on the submitted body: do them before
        // the write lock so the transaction holds it for the inserts alone.
        $renderer = app(RichTextRenderer::class);
        $body = $renderer->storage($attributes['body'] ?? '');
        $plain = $renderer->plain($body);
        $hasAttachment = isset($attributes['attachment']);
        $hasEmbeds = preg_match('/sgid=[\"\']/', $body) === 1;
        try {
            return DB::transaction(function () use ($room, $user, $attributes, $webhooks, &$createdBlob, $body, $plain, $hasAttachment, $hasEmbeds) {
                abort_unless($room->memberships()->where('user_id', $user->id)->exists(), 403);
                $message = $room->messages()->create(['creator_id' => $user->id, 'client_message_id' => $attributes['client_message_id'] ?? (string) Str::uuid()]);
                if ($hasAttachment) {
                    $blob = app(BlobStorage::class)->attach($message, $attributes['attachment']);
                    if ($attributes['attachment'] instanceof UploadedFile) {
                        $createdBlob = $blob;
                    }
                }
                // A new message has no rich text, embeds or index row yet: insert directly.
                $richText = RichText::create(['record_id' => $message->id, 'record_type' => 'Message', 'name' => 'body', 'body' => $body]);
                $message->setRelation('richText', $richText);
                if ($hasEmbeds) {
                    $this->embeds($richText, $body);
                }
                if ($hasAttachment && trim($plain) === '') {
                    $filename = $message->attachment()->with('blob')->first()?->blob?->filename;
                    if ($filename) {
                        $plain = $filename;
                    }
                }
                $room->touch();
                // Like Rails' after_create_commit (Message::Searchable#create_in_index, room receipt):
                // index and unread marks commit right after the message, before the response.
                DB::afterCommit(function () use ($room, $user, $message, $plain) {
                    DB::transaction(function () use ($room, $user, $message, $plain) {
                        DB::insert('INSERT INTO message_search_index(rowid,body) VALUES(?,?)', [$message->id, $plain]);
                        $room->memberships()->where('user_id', '!=', $user->id)->where('involvement', '!=', 'invisible')->where(fn ($q) => $q->whereNull('connected_at')->orWhere('connected_at', '<', now()->subMinute()))->update(['unread_at' => $message->created_at, 'updated_at' => now()]);
                    });
                });
                if ($room->type === 'Rooms::Direct') {
                    app(SidebarEvents::class)->refresh($room->users()->pluck('users.id')->all());
                }
                // DeliverMessageNotifications loads what it needs from the id.
                DB::afterCommit(fn () => app(Notifications::class)->message($message, $webhooks));

                return $message;
            });
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
        try {
            DB::transaction(function () use ($message, $attributes, &$createdBlob) {
                if (isset($attributes['attachment'])) {
                    $oldBlob = $message->attachment()->with('blob')->first()?->blob;
                    $blob = app(BlobStorage::class)->attach($message, $attributes['attachment']);
                    if ($attributes['attachment'] instanceof UploadedFile) {
                        $createdBlob = $blob;
                    }
                    if ($oldBlob && $oldBlob->id !== $blob->id) {
                        DB::afterCommit(fn () => app(BlobStorage::class)->purgeUnreferenced($oldBlob));
                    }
                }
                $this->body($message, $attributes['body'] ?? $message->richText?->body ?? '');
                $message->touch();
                $message->room->touch();
            });
        } catch (\Throwable $error) {
            if ($createdBlob && ! Blob::find($createdBlob->id)) {
                app(BlobStorage::class)->deleteFiles($createdBlob);
            }
            throw $error;
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
