<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Room;
use App\Support\Broadcasts;
use App\Support\MessageWriter;
use App\Support\RichTextRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChatController extends Controller
{
    public function root(Request $r)
    {
        $room = $r->user()->rooms()->orderByDesc('id')->first();

        return $room ? redirect('/rooms/'.$room->id) : redirect('/rooms/opens/new');
    }

    public function room(Request $r, int $id, ?int $message = null)
    {
        $room = $this->findRoom($r, $id);
        $query = $room->messages();
        if ($message) {
            $at = $room->messages()->findOrFail($message);
            $messages = $query->clone()->where('created_at', '<', $at->getRawOriginal('created_at'))->orderByDesc('created_at')->limit(40)->get()->reverse()->concat([$at])->concat($query->clone()->where('created_at', '>', $at->getRawOriginal('created_at'))->orderBy('created_at')->limit(40)->get());
        } else {
            $messages = $query->orderByDesc('created_at')->limit(40)->get()->reverse();
        }
        $r->session()->put('last_room_id', $room->id);

        return response()->view('rooms.show', compact('room', 'messages'))->withCookie(cookie('last_room', (string) $room->id, 60 * 24 * 365 * 20));
    }

    public function messages(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $q = $room->messages();
        if ($r->filled('before')) {
            $at = $room->messages()->findOrFail($r->input('before'));
            $q->where('created_at', '<', $at->getRawOriginal('created_at'));
        }
        if ($r->filled('after')) {
            $at = $room->messages()->findOrFail($r->input('after'));
            $messages = $q->where('created_at', '>', $at->getRawOriginal('created_at'))->orderBy('created_at')->limit(40)->get();
        } else {
            $messages = $q->orderByDesc('created_at')->limit(40)->get()->reverse();
        }
        if ($messages->isEmpty()) {
            return response('', 204);
        }
        if ($r->expectsJson()) {
            return response()->json($messages->map(fn ($m) => $this->json($m)));
        }

        return response()->view('messages.index', compact('messages'));
    }

    public function show(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->presentation()->findOrFail($id);

        return $r->expectsJson() ? response()->json($this->json($m)) : view('messages.show', ['message' => $m]);
    }

    public function edit(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->presentation()->findOrFail($id);
        abort_unless($r->user()->canAdminister($m), 403);

        return view('messages.edit', ['message' => $m]);
    }

    public function create(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $a = $r->validate(['message' => 'required|array', 'message.body' => 'nullable|string', 'message.client_message_id' => 'nullable|string|max:255', 'message.attachment' => 'nullable']);
        $m = app(MessageWriter::class)->create($room, $r->user(), $r->hasFile('message.attachment') ? array_merge($a['message'], ['attachment' => $r->file('message.attachment')]) : $a['message'], true)->load(['creator', 'richText', 'attachment.blob.variantRecords', 'boosts.booster', 'room']);
        $html = view('messages.message', ['message' => $m])->render();
        $stream = $this->stream('append', 'messages_room_'.$room->id, $html);
        app(Broadcasts::class)->room($room->id, $stream);
        foreach ($room->memberships()->pluck('user_id') as $user) {
            app(Broadcasts::class)->publish('user_'.$user.'_unreads', ['roomId' => $room->id]);
        }

        return $r->expectsJson() ? response()->json($this->json($m), 201) : response($stream, 200)->header('Content-Type', 'text/vnd.turbo-stream.html; charset=utf-8');
    }

    public function update(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->findOrFail($id);
        abort_unless($r->user()->canAdminister($m), 403);
        app(MessageWriter::class)->update($m, $r->input('message', []));
        $m->refresh()->load(['creator', 'richText', 'attachment.blob.variantRecords', 'boosts.booster', 'room']);
        app(Broadcasts::class)->room($room, $this->stream('replace', 'presentation_message_'.$m->client_message_id, view('messages.presentation', ['message' => $m])->render()));

        return $r->expectsJson() ? response()->json($this->json($m)) : redirect('/rooms/'.$room.'/messages/'.$id);
    }

    public function destroy(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->findOrFail($id);
        abort_unless($r->user()->canAdminister($m), 403);
        $target = 'message_'.$m->client_message_id;
        app(MessageWriter::class)->destroy($m);
        $s = $this->stream('remove', $target, '');
        app(Broadcasts::class)->room($room, $s);

        return response($s)->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    public function sidebar(Request $r)
    {
        $memberships = $r->user()->memberships()->where('involvement', '!=', 'invisible')->with('room.users')->get();
        $directs = $memberships->filter(fn ($m) => $m->room->type === 'Rooms::Direct')->sortByDesc(fn ($m) => $m->room->updated_at);
        $shared = $memberships->reject(fn ($m) => $m->room->type === 'Rooms::Direct')->sortBy(fn ($m) => mb_strtolower($m->room->name ?? ''));

        return view('users.sidebar', compact('directs', 'shared'));
    }

    public function search(Request $r)
    {
        $query = preg_replace('/[^\p{L}\p{N}_]/u', ' ', $r->input('q', ''));
        $messages = collect();
        if (trim($query) !== '') {
            $messages = Message::query()->join('message_search_index as idx', 'messages.id', '=', 'idx.rowid')->whereRaw('idx.body MATCH ?', [$query])->whereIn('room_id', $r->user()->rooms()->select('rooms.id'))->select('messages.*')->orderByDesc('messages.created_at')->limit(100)->get()->reverse();
        }

        return view('searches.index', compact('query', 'messages'));
    }

    public function recordSearch(Request $r)
    {
        $query = preg_replace('/[^\p{L}\p{N}_]/u', ' ', $r->input('q', ''));
        DB::table('searches')->updateOrInsert(['user_id' => $r->user()->id, 'query' => $query], ['created_at' => now(), 'updated_at' => now()]);

        return redirect('/searches?q='.urlencode($query));
    }

    public function clearSearch(Request $r)
    {
        DB::table('searches')->where('user_id', $r->user()->id)->delete();

        return redirect('/searches');
    }

    public function refresh(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $since = CarbonImmutable::createFromTimestampMs((int) $r->input('since', 0));
        $new = $room->messages()->presentation()->where('created_at', '>', $since)->orderBy('created_at')->limit(40)->get();
        $updated = $room->messages()->presentation()->whereNotIn('id', $new->pluck('id'))->where('updated_at', '>', $since)->orderByDesc('created_at')->limit(40)->get()->reverse();
        $s = '';
        foreach ($new as $m) {
            $s .= $this->stream('append', 'messages_room_'.$room->id, view('messages.message', ['message' => $m])->render());
        }foreach ($updated as $m) {
            $s .= $this->stream('replace', 'message_'.$m->client_message_id, view('messages.message', ['message' => $m])->render());
        }

        return response($s)->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    public function findRoom(Request $r, int $id): Room
    {
        return $r->user()->rooms()->findOrFail($id);
    }

    public function json(Message $m): array
    {
        return [
            'id' => $m->id,
            'created_at' => $m->created_at->toISOString(),
            'body' => ['plain_text' => $m->plainText(), 'html' => app(RichTextRenderer::class)->html($m->richText?->body ?? '')],
            'creator' => ['id' => $m->creator->id, 'name' => $m->creator->name, 'role' => ['member', 'administrator', 'bot'][$m->creator->role], 'avatar_url' => url($m->creator->avatarUrl())],
            'room' => ['id' => $m->room_id],
            'url' => url('/rooms/'.$m->room_id.'/messages/'.$m->id),
        ];
    }

    public function stream(string $action, string $target, string $html): string
    {
        return '<turbo-stream action="'.e($action).'" target="'.e($target).'"><template>'.$html.'</template></turbo-stream>';
    }
}
