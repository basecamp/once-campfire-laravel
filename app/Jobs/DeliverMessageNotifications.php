<?php

namespace App\Jobs;

use App\Models\Message;
use App\Support\ChatEvents;
use App\Support\MessageWriter;
use App\Support\RichTextRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class DeliverMessageNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId, public bool $webhooks = false) {}

    public function handle(): void
    {
        $m = Message::presentation()->find($this->messageId);
        if (! $m) {
            return;
        }
        $mentions = app(RichTextRenderer::class)->mentions($m->richText?->body ?? '');
        $bots = $m->room->isDirect() ? $m->room->users()->where('role', 2)->where('status', 0)->get() : $m->room->users()->where('role', 2)->where('status', 0)->whereIn('users.id', $mentions)->get();
        foreach ($this->webhooks ? $bots : [] as $bot) {
            if ($bot->id === $m->creator_id || ! $m->room->memberships()->where('user_id', $bot->id)->exists()) {
                continue;
            }
            $url = DB::table('webhooks')->where('user_id', $bot->id)->value('url');
            if (! $url) {
                continue;
            }
            $payload = ['user' => ['id' => $m->creator_id, 'name' => $m->creator->name], 'room' => ['id' => $m->room_id, 'name' => $m->room->name, 'path' => '/rooms/'.$m->room_id.'/'.$bot->id.'-'.$bot->bot_token.'/messages'], 'message' => ['id' => $m->id, 'body' => ['html' => $m->richText?->body ?? '', 'plain' => trim(str_replace('@'.$bot->name, '', $m->plainText()))], 'path' => '/rooms/'.$m->room_id.'/@'.$m->id]];
            try {
                $reply = Http::connectTimeout(7)->timeout(7)->withOptions(['allow_redirects' => false])->post($url, $payload);
                if ($reply->status() === 200 && in_array(strtok($reply->header('Content-Type'), ';'), ['text/plain', 'text/html'])) {
                    $created = app(MessageWriter::class)->create($m->room, $bot, ['body' => $reply->body()]);
                    app(ChatEvents::class)->created($created);
                } elseif ($reply->header('Content-Type') && strlen($reply->body()) <= 20 * 1024 * 1024) {
                    $mime = strtok($reply->header('Content-Type'), ';');
                    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf', 'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'application/json' => 'json', 'text/csv' => 'csv'];
                    if (isset($extensions[$mime])) {
                        $path = tempnam(storage_path('framework/cache'), 'webhook-');
                        try {
                            file_put_contents($path, $reply->body());
                            $file = new UploadedFile($path, 'attachment.'.$extensions[$mime], $mime, null, true);
                            $created = app(MessageWriter::class)->create($m->room, $bot, ['attachment' => $file]);
                            app(ChatEvents::class)->created($created);
                        } finally {
                            unlink($path);
                        }
                    }
                }
            } catch (ConnectionException) {
                $created = app(MessageWriter::class)->create($m->room, $bot, ['body' => 'Failed to respond within 7 seconds']);
                app(ChatEvents::class)->created($created);
            }
        }
        $query = DB::table('push_subscriptions as p')->join('memberships as ms', 'ms.user_id', '=', 'p.user_id')->where('ms.room_id', $m->room_id)->where('ms.user_id', '!=', $m->creator_id)->where(fn ($q) => $q->whereNull('ms.connected_at')->orWhere('ms.connected_at', '<', now()->subMinute()))->where(fn ($q) => $q->where('ms.involvement', 'everything')->orWhere(fn ($q) => $q->where('ms.involvement', 'mentions')->whereIn('ms.user_id', $mentions)))->select('p.*');
        $payload = ['title' => $m->room->isDirect() ? $m->creator->name : $m->room->name, 'body' => ($m->room->isDirect() ? '' : $m->creator->name.': ').$m->plainText(), 'path' => '/rooms/'.$m->room_id];
        foreach ($query->get() as $sub) {
            DeliverPush::dispatch((array) $sub, $payload);
        }
    }
}
