<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\MessageWriter;
use App\Support\SidebarEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RoomsController extends Controller
{
    private function type(string $kind): string
    {
        return match ($kind) {
            'opens' => 'Rooms::Open','closeds' => 'Rooms::Closed','directs' => 'Rooms::Direct',default => abort(404)
        };
    }

    public function new(Request $r, string $kind)
    {
        $this->creationPermission($r, $kind);

        return view('rooms.form', ['kind' => $kind, 'room' => null, 'users' => User::active()->orderByRaw('LOWER(name)')->get(), 'selected' => []]);
    }

    public function create(Request $r, string $kind)
    {
        $this->creationPermission($r, $kind);
        $type = $this->type($kind);
        $ids = User::active()->whereIn('id', $r->input('user_ids', []))->pluck('id')->all();
        if ($kind === 'opens') {
            $ids = [$r->user()->id];
        } elseif ($kind === 'directs') {
            $ids[] = $r->user()->id;
            $ids = array_values(array_unique($ids));
            sort($ids);
        }
        $room = DB::transaction(function () use ($r, $type, $ids) {
            if ($type === 'Rooms::Direct') {
                $candidate = Room::query()->where('type', $type)
                    ->whereIn('id', function ($query) use ($ids) {
                        $query->select('room_id')->from('memberships')->groupBy('room_id')
                            ->havingRaw('COUNT(*) = ?', [count($ids)])
                            ->havingRaw('SUM(user_id IN (SELECT value FROM json_each(?))) = ?', [json_encode($ids), count($ids)]);
                    })->orderBy('id')->first();
                if ($candidate) {
                    return $candidate;
                }
            }
            $room = Room::create(['name' => $r->input('room.name', 'New room'), 'type' => $type, 'creator_id' => $r->user()->id]);
            $members = $type === 'Rooms::Open' ? User::pluck('id')->all() : $ids;
            foreach ($members as $id) {
                Membership::create(['room_id' => $room->id, 'user_id' => $id, 'involvement' => $type === 'Rooms::Direct' ? 'everything' : 'mentions']);
            }

            return $room;
        });

        app(SidebarEvents::class)->refresh($room->users()->pluck('users.id')->all());

        return redirect('/rooms/'.$room->id);
    }

    public function show(Request $r, string $kind, int $id)
    {
        $room = $this->find($r, $kind, $id);

        return redirect('/rooms/'.$room->id);
    }

    public function deleteNamespaced(Request $r, string $kind, int $id)
    {
        return $this->destroy($r, $id, $kind);
    }

    public function edit(Request $r, string $kind, int $id)
    {
        $room = $this->find($r, $kind, $id);
        abort_unless($kind === 'directs' || $r->user()->canAdminister($room), 403);

        return view('rooms.form', ['kind' => $kind, 'room' => $room, 'users' => User::active()->orderByRaw('LOWER(name)')->get(), 'selected' => $room->users()->pluck('users.id')->all()]);
    }

    public function update(Request $r, string $kind, int $id)
    {
        $room = $this->find($r, $kind, $id);
        abort_unless($r->user()->canAdminister($room), 403);
        abort_if($room->type === 'Rooms::Direct', 403);
        $previousMembers = $room->users()->pluck('users.id')->all();
        DB::transaction(function () use ($r, $room, $kind) {
            $room->update(['name' => $r->input('room.name', 'New room'), 'type' => $this->type($kind)]);
            $ids = $kind === 'opens' ? User::pluck('id')->all() : User::whereIn('id', $r->input('user_ids', []))->pluck('id')->all();
            $room->memberships()->whereNotIn('user_id', $ids)->delete();
            foreach ($ids as $id) {
                Membership::firstOrCreate(['room_id' => $room->id, 'user_id' => $id], ['involvement' => 'mentions']);
            }
        });

        app(SidebarEvents::class)->refresh(array_merge($previousMembers, $room->users()->pluck('users.id')->all()));

        return redirect('/rooms/'.$id);
    }

    public function destroy(Request $r, int $id, ?string $kind = null)
    {
        $room = $kind ? $this->find($r, $kind, $id) : $r->user()->rooms()->findOrFail($id);
        abort_unless(($kind === 'directs' && $room->type === 'Rooms::Direct') || $r->user()->canAdminister($room), 403);
        $previousMembers = $room->users()->pluck('users.id')->all();
        DB::transaction(function () use ($room) {
            foreach ($room->messages()->get() as $m) {
                app(MessageWriter::class)->destroy($m);
            }$room->memberships()->delete();
            $room->delete();
        });

        if ($room->type === 'Rooms::Open') {
            app(SidebarEvents::class)->globalRemove($room->id);
        }
        app(SidebarEvents::class)->refresh($previousMembers);

        return redirect('/');
    }

    public function settings(Request $r, int $room)
    {
        $room = $r->user()->rooms()->findOrFail($room);

        return view('rooms.settings', compact('room'));
    }

    public function involvement(Request $r, int $room)
    {
        $m = $r->user()->memberships()->where('room_id', $room)->firstOrFail();
        if ($r->isMethod('PATCH') || $r->isMethod('PUT')) {
            $r->validate(['involvement' => 'required|in:invisible,nothing,mentions,everything']);
            $m->update(['involvement' => $r->input('involvement')]);
            app(SidebarEvents::class)->refresh([$r->user()->id]);

            return redirect('/rooms/'.$room.'/involvement');
        }

        return view('rooms.involvement', ['membership' => $m]);
    }

    private function find(Request $r, string $kind, int $id): Room
    {
        $q = $r->user()->rooms();
        $kind === 'directs' ? $q->where('type', 'Rooms::Direct') : $q->where('type', '!=', 'Rooms::Direct');

        return $q->findOrFail($id);
    }

    private function creationPermission(Request $r, string $kind): void
    {
        $this->type($kind);
        $settings = json_decode(DB::table('accounts')->value('settings') ?? '{}', true);
        abort_if($kind !== 'directs' && ($settings['restrict_room_creation_to_administrators'] ?? false) && $r->user()->role !== 1, 403);
    }
}
