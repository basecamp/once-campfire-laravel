<?php

namespace App\Http\Controllers;

use App\Models\Boost;
use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\BlobStorage;
use App\Support\Broadcasts;
use App\Support\ChatEvents;
use App\Support\MessageWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BotsController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->role === 1, 403);

        return view('bots.index', ['bots' => User::active()->where('role', 2)->get()]);
    }

    public function form(Request $r, ?int $id = null)
    {
        abort_unless($r->user()->role === 1, 403);
        $bot = $id ? User::active()->where('role', 2)->findOrFail($id) : null;

        return view('bots.form', ['bot' => $bot, 'webhook' => $bot ? DB::table('webhooks')->where('user_id', $bot->id)->value('url') : null]);
    }

    public function create(Request $r)
    {
        abort_unless($r->user()->role === 1, 403);
        $a = $r->validate(['user.name' => 'required|string', 'user.bio' => 'nullable|string', 'user.webhook_url' => 'nullable|url:http,https']);
        $bot = DB::transaction(function () use ($a) {
            $v = $a['user'];
            $url = $v['webhook_url'] ?? null;
            unset($v['webhook_url']);
            $bot = User::create($v + ['role' => 2, 'status' => 0, 'bot_token' => Str::random(12)]);
            foreach (Room::where('type', 'Rooms::Open')->pluck('id') as $room) {
                Membership::create(['room_id' => $room, 'user_id' => $bot->id, 'involvement' => 'mentions']);
            }
            if ($url) {
                DB::table('webhooks')->insert(['user_id' => $bot->id, 'url' => $url, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $bot;
        });

        if ($r->hasFile('user.avatar')) {
            app(BlobStorage::class)->attachTo('User', $bot->id, 'avatar', $r->file('user.avatar'));
        }

        return redirect('/account/bots');
    }

    public function update(Request $r, int $id)
    {
        abort_unless($r->user()->role === 1, 403);
        $bot = User::active()->where('role', 2)->findOrFail($id);
        $a = $r->validate(['user.name' => 'required|string', 'user.bio' => 'nullable|string', 'user.webhook_url' => 'nullable|url:http,https']);
        DB::transaction(function () use ($bot, $a) {
            $v = $a['user'];
            $url = $v['webhook_url'] ?? null;
            unset($v['webhook_url']);
            $bot->update($v);
            if ($url) {
                DB::table('webhooks')->updateOrInsert(['user_id' => $bot->id], ['url' => $url, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('webhooks')->where('user_id', $bot->id)->delete();
            }
        });

        if ($r->hasFile('user.avatar')) {
            app(BlobStorage::class)->attachTo('User', $bot->id, 'avatar', $r->file('user.avatar'));
        }

        return redirect('/account/bots');
    }

    public function resetKey(Request $r, int $id)
    {
        abort_unless($r->user()->role === 1, 403);
        User::active()->where('role', 2)->findOrFail($id)->update(['bot_token' => Str::random(12)]);

        return redirect('/account/bots');
    }

    public function destroy(Request $r, int $id)
    {
        abort_unless($r->user()->role === 1, 403);
        $bot = User::active()->where('role', 2)->findOrFail($id);
        $bot->deactivate();

        return redirect('/account/bots');
    }

    public function boost(Request $r, int $room, string $key, int $id, ?int $boost = null)
    {
        $p = explode('-', trim($key), 2);
        $bot = count($p) === 2 ? User::active()->where('role', 2)->where('bot_token', $p[1])->find($p[0]) : null;
        abort_unless($bot, 401);
        $room = $bot->rooms()->findOrFail($room);
        $m = $room->messages()->findOrFail($id);
        if ($r->isMethod('POST')) {
            $content = $r->getContent();
            abort_unless(trim($content) !== '' && mb_strlen($content) <= 16, 422);
            $b = Boost::create(['message_id' => $id, 'booster_id' => $bot->id, 'content' => $content]);
            $s = app(ChatController::class)->stream('append', 'boosts_message_'.$m->client_message_id, view('boosts.boost', ['boost' => $b->load('booster')])->render());
            app(Broadcasts::class)->room($room->id, $s);

            return response()->json(['id' => $b->id, 'content' => $b->content, 'booster' => ['id' => $bot->id, 'name' => $bot->name]], 201);
        }
        $b = $m->boosts()->where('booster_id', $bot->id)->findOrFail($boost);
        $b->delete();
        app(Broadcasts::class)->room($room->id, app(ChatController::class)->stream('remove', 'boost_'.$boost, ''));

        return response('', 204);
    }

    public function api(Request $r, int $room, string $key, ?int $id = null)
    {
        $p = explode('-', trim($key), 2);
        $bot = count($p) === 2 ? User::active()->where('role', 2)->where('bot_token', $p[1])->find($p[0]) : null;
        abort_unless($bot, 401);
        $room = $bot->rooms()->findOrFail($room);
        $controller = app(ChatController::class);
        if ($r->isMethod('GET')) {
            $query = $room->messages()->presentation();
            if ($r->filled('before') || $r->filled('after')) {
                $anchor = $room->messages()->findOrFail($r->input('after', $r->input('before')));
                $query->where('created_at', $r->filled('after') ? '>' : '<', $anchor->getRawOriginal('created_at'));
            }
            $messages = $r->filled('after') ? $query->orderBy('created_at')->limit(40)->get() : $query->orderByDesc('created_at')->limit(40)->get()->reverse()->values();
            $response = response()->json($messages->map(fn ($message) => $controller->json($message)))->header('X-Total-Count', $room->messages()->count());
            if ($messages->isNotEmpty()) {
                $anchor = $r->filled('after') ? $messages->last() : $messages->first();
                $direction = $r->filled('after') ? 'after' : 'before';
                if ($room->messages()->where('created_at', $direction === 'after' ? '>' : '<', $anchor->getRawOriginal('created_at'))->exists()) {
                    $response->header('Link', '<'.url('/rooms/'.$room->id.'/'.$key.'/messages').'?'.$direction.'='.$anchor->id.'>; rel="next"');
                }
            }

            return $response;
        }
        if ($r->isMethod('POST')) {
            $attrs = $r->input('message', []);
            if ($r->hasFile('attachment')) {
                $attrs['attachment'] = $r->file('attachment');
            } elseif ($r->filled('attachment')) {
                $attrs['attachment'] = $r->input('attachment');
            } elseif (! $attrs) {
                $attrs = ['body' => $r->getContent()];
            }
            abort_unless(! empty($attrs['body']) || isset($attrs['attachment']), 422);
            $m = app(MessageWriter::class)->create($room, $bot, $attrs, true);
            app(ChatEvents::class)->created($m);

            return response('', 201)->header('Location', url('/rooms/'.$room->id.'/messages/'.$m->id));
        }
        $m = $room->messages()->where('creator_id', $bot->id)->findOrFail($id);
        if ($r->isMethod('DELETE')) {
            $target = 'message_'.$m->client_message_id;
            app(MessageWriter::class)->destroy($m);
            app(Broadcasts::class)->room($room->id, $controller->stream('remove', $target, ''));

            return response('', 204);
        }
        app(MessageWriter::class)->update($m, $r->input('message', ['body' => $r->getContent()]));

        return response()->json($controller->json($m->fresh()->load('creator', 'richText')));
    }
}
