<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\BlobStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PeopleController extends Controller
{
    public function firstRun()
    {
        return DB::table('accounts')->exists() ? redirect('/') : view('users.signup', ['action' => '/first_run']);
    }

    public function provision(Request $r)
    {
        abort_if(DB::table('accounts')->exists(), 403);
        $user = DB::transaction(function () use ($r) {
            $a = $r->validate(['user.name' => 'required|string', 'user.email_address' => 'required|email', 'user.password' => 'required|string']);
            $now = now();
            DB::table('accounts')->insert(['name' => 'Campfire', 'join_code' => Str::random(4).'-'.Str::random(4).'-'.Str::random(4), 'settings' => '{}', 'singleton_guard' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $values = $a['user'] ?? [];
            unset($values['password']);
            $u = User::create($values + ['password_digest' => password_hash($a['user']['password'], PASSWORD_BCRYPT), 'role' => 1, 'status' => 0]);
            $u->offsetUnset('password');
            $room = Room::create(['name' => 'All Talk', 'type' => 'Rooms::Open', 'creator_id' => $u->id]);
            Membership::create(['room_id' => $room->id, 'user_id' => $u->id, 'involvement' => 'mentions']);

            return $u;
        });

        if ($r->hasFile('user.avatar')) {
            app(BlobStorage::class)->attachTo('User', $user->id, 'avatar', $r->file('user.avatar'));
        }

        return app(SessionController::class)->start($r, $user);
    }

    public function join(Request $r, string $code)
    {
        abort_unless(hash_equals(DB::table('accounts')->value('join_code') ?? '', $code), 404);
        if ($r->isMethod('GET')) {
            return view('users.signup', ['action' => '/join/'.$code]);
        }
        $a = $r->validate(['user.name' => 'required|string', 'user.email_address' => 'required|email', 'user.password' => 'required|string']);
        $v = $a['user'];
        unset($v['password']);
        $u = DB::transaction(function () use ($a, $v) {
            $u = User::create($v + ['password_digest' => password_hash($a['user']['password'], PASSWORD_BCRYPT), 'role' => 0, 'status' => 0]);
            foreach (Room::where('type', 'Rooms::Open')->pluck('id') as $room) {
                Membership::create(['room_id' => $room, 'user_id' => $u->id, 'involvement' => 'mentions']);
            }

            return $u;
        });

        if ($r->hasFile('user.avatar')) {
            app(BlobStorage::class)->attachTo('User', $u->id, 'avatar', $r->file('user.avatar'));
        }

        return app(SessionController::class)->start($r, $u);
    }

    public function show(Request $r, int $id)
    {
        $user = User::findOrFail($id);

        return view('users.show', compact('user'));
    }

    public function profile(Request $r)
    {
        $user = $r->user();
        if ($r->isMethod('PATCH') || $r->isMethod('PUT')) {
            $a = $r->validate(['user.name' => 'sometimes|string', 'user.email_address' => 'sometimes|email', 'user.bio' => 'nullable|string', 'user.password' => 'nullable|string']);
            $values = $a['user'] ?? [];
            if (! empty($values['password'])) {
                $values['password_digest'] = password_hash($values['password'], PASSWORD_BCRYPT);
            }
            unset($values['password']);
            $user->update($values);
            if ($r->hasFile('user.avatar')) {
                app(BlobStorage::class)->attachTo('User', $user->id, 'avatar', $r->file('user.avatar'));
            }

            return redirect('/users/me/profile');
        }

        return view('users.profile', compact('user'));
    }

    public function autocomplete(Request $r)
    {
        $q = $r->input('filter', $r->input('query', ''));
        $users = $r->filled('room_id') ? $r->user()->rooms()->findOrFail($r->input('room_id'))->users() : User::query();
        $users = $users->where('status', 0)->where('name', 'like', '%'.$q.'%')->orderByRaw('LOWER(name)')->limit(20)->get();
        if ($r->expectsJson()) {
            return response()->json($users->map(fn ($u) => ['value' => $u->id, 'id' => $u->id, 'name' => $u->name, 'avatar_url' => $u->avatarUrl()]));
        }

        return view('users.autocomplete', compact('users'));
    }

    public function account(Request $r)
    {
        $account = DB::table('accounts')->first();
        if ($r->isMethod('PATCH') || $r->isMethod('PUT')) {
            abort_unless($r->user()->role === 1, 403);
            $v = $r->validate(['account.name' => 'sometimes|string', 'account.settings' => 'sometimes|array']);
            $values = $v['account'] ?? [];
            if (isset($values['settings'])) {
                $settings = $values['settings'];
                if (array_key_exists('restrict_room_creation_to_administrators', $settings)) {
                    $settings['restrict_room_creation_to_administrators'] = filter_var($settings['restrict_room_creation_to_administrators'], FILTER_VALIDATE_BOOLEAN);
                }
                $values['settings'] = json_encode($settings);
            }DB::table('accounts')->where('id', $account->id)->update($values + ['updated_at' => now()]);
            if ($r->hasFile('account.logo')) {
                app(BlobStorage::class)->attachTo('Account', $account->id, 'logo', $r->file('account.logo'));
            }

            return redirect('/account/edit');
        }
        $users = User::whereIn('status', $r->user()->role === 1 ? [0, 2] : [0])->where('role', '!=', 2)->orderByRaw('LOWER(name)')->get();

        return view('users.account', compact('account', 'users'));
    }

    public function customStyles(Request $r)
    {
        abort_unless($r->user()->role === 1, 403);
        if ($r->isMethod('PATCH')) {
            DB::table('accounts')->update(['custom_styles' => $r->input('account.custom_styles'), 'updated_at' => now()]);

            return redirect('/account/edit');
        }

        return view('users.styles', ['styles' => DB::table('accounts')->value('custom_styles')]);
    }

    public function resetJoinCode(Request $r)
    {
        abort_unless($r->user()->role === 1, 403);
        DB::table('accounts')->update(['join_code' => Str::random(4).'-'.Str::random(4).'-'.Str::random(4), 'updated_at' => now()]);

        return redirect('/account/edit');
    }

    public function member(Request $r, int $id)
    {
        abort_unless($r->user()->role === 1, 403);
        $u = User::active()->findOrFail($id);
        if ($r->isMethod('DELETE')) {
            $u->deactivate();
        } else {
            $u->update(['role' => $r->input('user.role') === 'administrator' ? 1 : 0]);
        }

        return redirect('/account/edit');
    }

    public function ban(Request $r, int $id)
    {
        abort_unless($r->user()->role === 1, 403);
        $u = User::findOrFail($id);
        DB::transaction(function () use ($r, $u) {
            if ($r->isMethod('DELETE')) {
                DB::table('bans')->where('user_id', $u->id)->delete();
                $u->update(['status' => 0]);
            } else {
                $ips = DB::table('sessions')->where('user_id', $u->id)->whereNotNull('ip_address')->distinct()->pluck('ip_address');
                foreach ($ips as $ip) {
                    DB::table('bans')->insert(['user_id' => $u->id, 'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now()]);
                }DB::table('sessions')->where('user_id', $u->id)->delete();
                $u->update(['status' => 2]);
            }
        });

        return redirect('/users/'.$id);
    }
}
