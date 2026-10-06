<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Room;
use App\Support\Broadcasts;
use App\Support\ChatEvents;
use App\Support\HotCache;
use App\Support\MessageFragments;
use App\Support\MessageWriter;
use App\Support\RichTextRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChatController extends Controller
{
    private const PAGE = 40;

    public function root(Request $r)
    {
        $room = $r->user()->rooms()->orderByDesc('id')->first();

        return $room ? redirect('/rooms/'.$room->id) : redirect('/rooms/opens/new');
    }

    public function room(Request $r, int $id, ?int $message = null)
    {
        $room = $this->findRoom($r, $id);
        $fragments = app(MessageFragments::class);
        if ($message) {
            $anchor = $room->messages()->findOrFail($message);
            $at = $anchor->getRawOriginal('created_at');
            $messages = $room->messages()->where('created_at', '<', $at)->orderByDesc('created_at')->limit(self::PAGE)->get()->reverse()
                ->push($anchor)
                ->concat($room->messages()->where('created_at', '>', $at)->orderBy('created_at')->limit(self::PAGE)->get());
            $messagesHtml = $fragments->render($messages);
            $html = view('rooms.show', compact('room', 'messagesHtml'))->render();
        } else {
            $version = $this->pageVersion($room->id);
            $generation = $room->id.'|'.$version.'|'.$room->getRawOriginal('updated_at');
            // Opt 4: per-session room shell — on hit skip rebuilding messagesHtml.
            $html = $this->cachedShell($r, 'room', $generation, function () use ($fragments, $room, $version) {
                $messagesHtml = $version === null ? '' : $fragments->block('room:'.$room->id.':'.$version, fn () => $this->latest($room));

                return view('rooms.show', compact('room', 'messagesHtml'))->render();
            });
        }

        $response = response($html);
        // Opt 3: skip rewriting last_room when the request already has this room id.
        if ((string) $r->cookie('last_room') !== (string) $room->id) {
            $response->withCookie(cookie('last_room', (string) $room->id, 60 * 24 * 365 * 20));
        }

        return $response;
    }

    public function messages(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $before = null;
        if ($r->filled('before')) {
            $before = DB::scalar('SELECT created_at FROM messages WHERE id = ? AND room_id = ?', [$r->input('before'), $room->id]);
            abort_if($before === null, 404);
        }
        if (! $r->filled('after') && ! $r->expectsJson()) {
            $version = $this->pageVersion($room->id, $before);
            if ($version === null) {
                return response('', 204);
            }

            return response(app(MessageFragments::class)->block('page:'.$room->id.':'.$version, fn () => $this->latest($room, $before)));
        }

        $query = $room->messages();
        if ($before !== null) {
            $query->where('created_at', '<', $before);
        }
        if ($r->filled('after')) {
            $after = $room->messages()->findOrFail($r->input('after'))->getRawOriginal('created_at');
            $messages = $query->where('created_at', '>', $after)->orderBy('created_at')->limit(self::PAGE)->get();
        } else {
            $messages = $query->orderByDesc('created_at')->limit(self::PAGE)->get()->reverse();
        }

        if ($messages->isEmpty()) {
            return response('', 204);
        }
        if ($r->expectsJson()) {
            return response()->json($messages->load(Message::PRESENTATION)->map(fn ($m) => $this->json($m))->values());
        }

        return response()->view('messages.index', ['messagesHtml' => app(MessageFragments::class)->render($messages)]);
    }

    /**
     * The newest page of a room's messages, oldest first.
     *
     * @return Collection<int, Message>
     */
    private function latest(Room $room, ?string $before = null)
    {
        $query = $room->messages()->orderByDesc('created_at')->limit(self::PAGE);
        if ($before !== null) {
            $query->where('created_at', '<', $before);
        }

        return $query->get()->reverse();
    }

    /**
     * Identifies the exact content of a message page without hydrating it.
     *
     * Opt 2: on warm hits, skip the 40-row join — cache the fingerprint under a cheap
     * APCu generation key (room.updated_at + global user generation + before cursor).
     */
    private function pageVersion(int $roomId, ?string $before = null): ?string
    {
        $roomUpdated = DB::scalar('SELECT updated_at FROM rooms WHERE id = ?', [$roomId]);
        if ($roomUpdated === null) {
            return null;
        }
        $userGen = $this->userGeneration();
        $genKey = 'pvgen:'.$roomId.':'.($before ?? '').':'.$roomUpdated.':'.$userGen;
        $cached = HotCache::get($genKey);
        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }

        $rows = DB::select(
            'SELECT m.id, m.updated_at, u.updated_at AS creator_updated_at FROM messages m LEFT JOIN users u ON u.id = m.creator_id WHERE m.room_id = ?'
            .($before !== null ? ' AND m.created_at < ?' : '').' ORDER BY m.created_at DESC LIMIT '.self::PAGE,
            $before !== null ? [$roomId, $before] : [$roomId]
        );
        if ($rows === []) {
            HotCache::put($genKey, '', 3600);

            return null;
        }
        $version = '';
        foreach ($rows as $row) {
            $version .= $row->id.'|'.$row->updated_at.'|'.$row->creator_updated_at.';';
        }
        HotCache::put($genKey, $version, 3600);

        return $version;
    }

    /** Cheap, APCu-memoized MAX(users.updated_at) used as a global creator-version generation. */
    private function userGeneration(): string
    {
        return (string) HotCache::remember('gen:users', 30, fn () => (string) DB::scalar('SELECT MAX(updated_at) FROM users'));
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
        $attributes = $r->validate([
            'message' => 'required|array',
            'message.body' => 'nullable|string',
            'message.client_message_id' => 'nullable|string|max:255',
            'message.attachment' => 'nullable',
        ])['message'];
        if ($r->hasFile('message.attachment')) {
            $attributes['attachment'] = $r->file('message.attachment');
        }

        $m = app(MessageWriter::class)->create($room, $r->user(), $attributes, true);
        // Opt 12: one Blade/cache pass → broadcast (empty CSRF) + viewer HTML (no second Blade).
        [$broadcastHtml, $html] = app(MessageFragments::class)->renderPair([$m]);
        app(ChatEvents::class)->created($m, $broadcastHtml);

        if ($r->expectsJson()) {
            return response()->json($this->json($m->loadMissing(Message::PRESENTATION)), 201);
        }

        return response($this->stream('append', 'messages_room_'.$room->id, $html))
            ->header('Content-Type', 'text/vnd.turbo-stream.html; charset=utf-8');
    }

    public function update(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->findOrFail($id);
        abort_unless($r->user()->canAdminister($m), 403);
        app(MessageWriter::class)->update($m, $r->input('message', []));
        $m->refresh()->load(Message::PRESENTATION);
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

    /**
     * Unscoped message routes that take the room as a `room_id` parameter.
     */
    public function legacy(Request $r, ?int $id = null)
    {
        $room = (int) $r->input('room_id');
        abort_unless($room, 404);

        return match ($r->method()) {
            'GET' => $id ? $this->show($r, $room, $id) : $this->messages($r, $room),
            'POST' => $this->create($r, $room),
            'PATCH', 'PUT' => $this->update($r, $room, $id),
            'DELETE' => $this->destroy($r, $room, $id),
        };
    }

    public function sidebar(Request $r)
    {
        $user = $r->user();
        // Opt 4: APCu-cache sidebar HTML per user + cheap generation (no CSRF in this partial).
        $gen = $this->sidebarGeneration($user->id);
        $key = 'sidebar:'.$user->id.':'.$gen.':'.$this->userGeneration();

        $html = HotCache::remember($key, 3600, function () use ($user) {
            [$directs, $shared] = $user->sidebar();

            return view('users.sidebar', compact('directs', 'shared'))->render();
        });

        return response($html);
    }

    /** Cheap generation for sidebar: membership/room stamps + unread-bearing row count. */
    private function sidebarGeneration(int $userId): string
    {
        $row = DB::selectOne(
            "SELECT MAX(m.updated_at) AS mu, MAX(r.updated_at) AS ru, COUNT(*) AS c,
                    SUM(CASE WHEN m.unread_at IS NULL THEN 0 ELSE 1 END) AS unread
             FROM memberships m JOIN rooms r ON r.id = m.room_id
             WHERE m.user_id = ? AND m.involvement != 'invisible'",
            [$userId]
        );

        return ($row->mu ?? '').'|'.($row->ru ?? '').'|'.($row->c ?? 0).'|'.($row->unread ?? 0);
    }

    public function search(Request $r)
    {
        $query = preg_replace('/[^\p{L}\p{N}_]/u', ' ', $r->input('q', ''));
        $version = '';
        if (trim($query) !== '') {
            $user = $r->user();
            // Opt 2: cheap search generation — one aggregate instead of fetching every room row.
            $userGen = $this->userGeneration();
            $row = DB::selectOne(
                'SELECT MAX(r.updated_at) AS ru, MAX(m.updated_at) AS mu, COUNT(*) AS c
                 FROM rooms r JOIN memberships m ON m.room_id = r.id WHERE m.user_id = ?',
                [$user->id]
            );
            $roomsGen = ($row->ru ?? '').'|'.($row->mu ?? '').'|'.($row->c ?? 0);
            $version = $query.'|'.$userGen.'|'.$roomsGen;
        }

        // Opt 4: per-session search shell — on hit skip search block rebuild.
        $html = $this->cachedShell($r, 'search', $version.'|'.$query, function () use ($query, $version) {
            $messagesHtml = '';
            if ($version !== '') {
                $messagesHtml = app(MessageFragments::class)->block('search:'.$version, fn () => Message::query()
                    ->join('message_search_index as idx', 'messages.id', '=', 'idx.rowid')
                    ->whereRaw('idx.body MATCH ?', [$query])
                    ->whereIn('room_id', request()->user()->rooms()->select('rooms.id'))
                    ->select('messages.*')
                    ->orderByDesc('messages.created_at')
                    ->limit(100)
                    ->get()
                    ->reverse());
            }

            return view('searches.index', compact('query', 'messagesHtml'))->render();
        });

        return response($html);
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
        $new = $room->messages()->where('created_at', '>', $since)->orderBy('created_at')->limit(self::PAGE)->get();
        $updated = $room->messages()->whereNotIn('id', $new->pluck('id'))->where('updated_at', '>', $since)->orderByDesc('created_at')->limit(self::PAGE)->get()->reverse()->values();

        $fragments = app(MessageFragments::class);
        $s = '';
        foreach ($fragments->each($new) as $html) {
            $s .= $this->stream('append', 'messages_room_'.$room->id, $html);
        }
        foreach ($fragments->each($updated) as $i => $html) {
            $s .= $this->stream('replace', 'message_'.$updated[$i]->client_message_id, $html);
        }

        return response($s)->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    public function findRoom(Request $r, int $id): Room
    {
        $row = DB::selectOne('SELECT r.* FROM rooms r JOIN memberships m ON m.room_id = r.id WHERE m.user_id = ? AND r.id = ? LIMIT 1', [$r->user()->id, $id]);
        abort_if($row === null, 404);

        $room = (new Room)->newFromBuilder((array) $row);
        // Opt 8: MessageWriter can skip a second membership EXISTS inside the write lock.
        $room->setAttribute('_membership_ok', true);

        return $room;
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

    /**
     * Opt 4: cache fully rendered HTML shells per session + generation.
     * CSRF tokens in the shell belong to this session only — never share across sessions.
     *
     * @param  callable(): string  $render
     */
    private function cachedShell(Request $r, string $kind, string $generation, callable $render): string
    {
        $sessionId = $r->attributes->get('campfire_session_id');
        if ($sessionId === null || $sessionId === '') {
            return $render();
        }
        $token = (string) csrf_token();
        $key = 'shell:'.$kind.':'.hash('xxh128', $sessionId.'|'.$token.'|'.$generation.'|'.url('/'));

        return HotCache::remember($key, 3600, $render);
    }
}
