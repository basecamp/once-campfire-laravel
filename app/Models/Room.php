<?php

namespace App\Models;

final class Room extends Record
{
    public function messages()
    {
        return $this->hasMany(Message::class)->chaperone('room');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'memberships');
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function isDirect(): bool
    {
        return $this->type === 'Rooms::Direct';
    }

    public function displayName(?User $viewer = null): string
    {
        if (! $this->isDirect()) {
            return $this->name ?? '';
        }
        if ($this->relationLoaded('users')) {
            return $this->users->where('id', '!=', $viewer?->id)->pluck('name')->join(', ');
        }
        $attributes = app()->bound('request') ? request()->attributes : null;
        $names = $attributes?->get('campfire.direct_names', []) ?? [];
        if (! array_key_exists($this->id, $names)) {
            $names[$this->id] = $this->users()->pluck('name', 'users.id')->all();
            $attributes?->set('campfire.direct_names', $names);
        }
        $out = [];
        foreach ($names[$this->id] as $id => $name) {
            if ($viewer === null || (int) $id !== $viewer->id) {
                $out[] = $name;
            }
        }

        return implode(', ', $out);
    }
}
