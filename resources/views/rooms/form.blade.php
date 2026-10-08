@extends('layouts.app', ['title' => $room ? 'Edit settings' : ($kind === 'directs' ? 'New Ping' : 'New chat room')])
@section('nav')<a href="/" class="btn">Back to chat</a>@endsection
@section('content')
@php($assets = app(\App\Support\Assets::class))
@if($kind === 'directs' && $room)
<div class="panel txt-align-center"><section class="directs--edit margin-block-end">
@foreach($room->users->where('id','!=',$currentUser->id) as $member)<div class="member flex flex-column gap fill-shade pad border-radius"><figure class="avatar center"><img src="{{ $member->avatarUrl() }}" width="100" height="100" loading="lazy" alt=""></figure><strong>{{ $member->name }}</strong></div>@endforeach
</section><form action="/rooms/directs/{{ $room->id }}" method="post"> @method('DELETE')<button class="btn btn--negative center" type="submit" aria-label="Delete Ping" data-turbo-confirm="Are you sure you want to delete this ping and all messages in it? This can’t be undone.">Delete Ping</button></form></div>
@elseif($kind === 'directs')
<turbo-frame id="direct_rooms_control" target="_top"><div class="directs directs--new flex flex-column gap">
<form action="/rooms/directs" method="post" class="flex gap flex-item-grow" data-controller="form" data-action="keydown.esc->form#cancel">
<a href="/users/me/sidebar" class="btn flex-item-no-shrink" data-turbo-frame="user_sidebar" data-form-target="cancel">Cancel changes</a>
<section class="autocomplete__container unpad input input--actor"><div class="autocomplete__input input flex flex-wrap position-relative flex-item-grow" data-controller="autocomplete" data-autocomplete-url-value="/autocompletable/users">
<select name="user_ids[]" data-autocomplete-target="select" data-template-id="autocompletable-user" multiple hidden required></select>
<template id="autocompletable-user"><div class="autocomplete__pill max-width" data-value="" tabindex="0"><img class="avatar flex-item-no-shrink" data-content="avatar" src=""><span class="autocomplete-field__selected-value-text overflow-ellipsis flex-item-grow" data-content="label"></span><button type="button" data-action="autocomplete#remove:prevent" data-value="" tabindex="-1" class="btn btn--plain txt-small translucent flex-item-no-shrink"><img src="{{ $assets->path('remove-circle.svg') }}" aria-hidden="true"><span class="for-screen-reader">Remove <span data-content="screenReaderLabel"></span></span></button></div></template>
<input type="text" name="user_ids_input" autocomplete="off" autocorrect="off" data-1p-ignore="true" class="autocomplete__input input flex flex-wrap position-relative" data-autocomplete-target="input" data-action="input->autocomplete#search keydown->autocomplete#didPressKey">
</div></section><button class="btn btn--reversed flex-item-no-shrink" type="submit">Start Ping</button></form><span class="txt-small translucent pad-inline-half center">Type names to ping someone…</span>
</div></turbo-frame>
@else
<section class="panel flex flex-column gap">
<form action="/rooms/{{ $kind }}{{ $room ? '/'.$room->id : '' }}" method="post" data-controller="form"> @if($room)@method('PATCH')@endif
<label class="flex-item-grow txt-large"><span class="for-screen-reader">Name this room</span><input class="input full-width" name="room[name]" id="room_name" value="{{ $room?->name ?? '' }}" required autofocus placeholder="Name the room" data-turbo-permanent="true" data-action="keydown.enter->form#submit:prevent"></label>
<section class="room-access margin-block pad-inline fill-shade border-radius">
<div class="flex align-center gap"><strong class="flex-item-grow">Everyone</strong><a class="btn" href="/rooms/{{ $kind === 'opens' ? 'closeds' : 'opens' }}/{{ $room ? $room->id.'/edit' : 'new' }}">{{ $kind === 'opens' ? 'Give only some access to this room' : 'Give everyone access to this room' }}</a></div>
<menu class="flex flex-column gap margin-none pad">
@foreach($users as $member)
<li class="flex align-center gap margin-none" data-value="{{ mb_strtolower($member->name) }}"><figure class="avatar flex-item-no-shrink"><img src="{{ $member->avatarUrl() }}" width="40" height="40" alt=""></figure><div class="min-width overflow-ellipsis fill-shade"><strong>{{ $member->name }}</strong></div><hr class="separator" aria-hidden="true">
@if($kind === 'opens')<img src="{{ $assets->path('check.svg') }}" width="20" height="20" aria-hidden="true">
@elseif(!$room && $member->id === $currentUser->id)<input type="hidden" name="user_ids[]" value="{{ $member->id }}"><img src="{{ $assets->path('check.svg') }}" width="20" height="20" aria-hidden="true">
@else<label class="switch flex-item-no-shrink"><input type="checkbox" name="user_ids[]" value="{{ $member->id }}" class="switch__input" @checked(in_array($member->id,$selected))><span class="switch__btn round"></span><span class="for-screen-reader">Give {{ $member->name }} access to this room</span></label>@endif
</li>
@endforeach
</menu></section><button class="btn btn--reversed txt-large center" type="submit">Save</button>
</form>
@if($room)<form action="/rooms/{{ $kind }}/{{ $room->id }}" method="post"> @method('DELETE')<button class="btn btn--negative center" type="submit" aria-label="Delete {{ $room->name }}" data-turbo-confirm="Are you sure you want to delete this room and all messages in it? This can’t be undone.">Delete {{ $room->name }}</button></form>@endif
</section>
@endif
@endsection
