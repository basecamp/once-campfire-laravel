<?php

namespace App\Models;

final class Boost extends Record
{
    protected $touches = ['message'];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function booster()
    {
        return $this->belongsTo(User::class, 'booster_id');
    }
}
