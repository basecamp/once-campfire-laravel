@extends('layouts.app', ['title' => 'Account settings'])
@section('nav')
<a href="/" class="btn">Back to chat</a>
@if($currentUser->role === 1)<div class="flex gap flex-item-justify-end"><a href="/account/bots" class="btn">Set up chat bots</a><a href="/account/custom_styles/edit" class="btn">Custom styles</a></div>@endif
@endsection
@section('content')
<section class="panel txt-align-center flex flex-column gap" style="view-transition-name: account-settings">
@if($currentUser->role === 1)
<div class="align-center center avatar__form gap" data-controller="upload-preview">
<form action="/account" method="post" enctype="multipart/form-data" data-controller="form"> @method('PATCH')
<label class="btn input--file"><input type="file" name="account[logo]" accept="image/*" data-action="upload-preview#previewImage change->form#submit"><span>Upload logo</span></label>
<img src="/account/logo" width="48" height="48" data-upload-preview-target="image" alt="Account logo">
</form>
@if(\App\Models\Attachment::where('record_type','Account')->where('record_id',$account->id)->where('name','logo')->exists())
<form action="/account/logo" method="post"> @method('DELETE')<button class="btn btn--negative avatar__delete-btn" type="submit">Delete logo</button></form>
@endif
</div>
<form action="/account" method="post" class="flex flex-column gap" data-controller="form"> @method('PATCH')
<div class="flex align-center gap"><label class="flex-item-grow"><span class="for-screen-reader">Account name</span><input class="input txt-large full-width" name="account[name]" value="{{ $account->name }}" required autofocus placeholder="Name this account" data-action="keydown.enter->form#submit"></label><button class="btn btn--reversed" type="submit">Save changes</button></div>
</form>
@php($restricted = (bool) (json_decode($account->settings ?? '{}', true)['restrict_room_creation_to_administrators'] ?? false))
<form action="/account" method="post" class="flex align-center gap center" data-controller="form"> @method('PUT')
<input type="hidden" name="account[settings][restrict_room_creation_to_administrators]" value="{{ $restricted ? 'false' : 'true' }}">
<label class="switch"><input type="checkbox" class="switch__input" @checked($restricted) data-action="change->form#submit"><span class="switch__btn round"></span><span>Must be admin to create new rooms</span></label>
</form>
@endif
<div class="margin-block pad-block pad-inline fill-shade border-radius">
<h1 class="txt-large">Invite people</h1>
<a href="/join/{{ $account->join_code }}" class="btn">{{ url('/join/'.$account->join_code) }}</a>
<button class="btn" data-controller="copy-to-clipboard" data-action="copy-to-clipboard#copy" data-copy-to-clipboard-content-value="{{ url('/join/'.$account->join_code) }}" data-copy-to-clipboard-success-class="btn--success">Copy invitation link</button>
<a class="btn" href="/qr_code/{{ rtrim(strtr(base64_encode(url('/join/'.$account->join_code)), '+/', '-_'), '=') }}">QR code</a>
@if($currentUser->role === 1)<form action="/account/join_code" method="post"><button type="submit" class="btn btn--negative" data-turbo-confirm="Are you sure you want to generate a new invite code?">Generate a new invite code</button></form>@endif
</div>
<menu class="flex flex-column gap margin-none pad">
@foreach($users as $u)
<li class="flex align-center gap margin-none {{ $u->status === 2 ? 'banned' : '' }}">
<figure class="avatar flex-item-no-shrink"><img src="{{ $u->avatarUrl() }}" width="36" height="36" loading="lazy" alt=""></figure>
<div class="min-width overflow-ellipsis fill-shade"><strong>{{ $u->name }}</strong></div><hr class="separator" aria-hidden="true">
@if($currentUser->role === 1 && $u->status === 0)
<form action="/account/users/{{ $u->id }}" method="post" data-controller="form"> @method('PATCH')<input type="hidden" name="user[role]" value="member">
<label class="btn txt-small flex-item-no-shrink" for="role_user_{{ $u->id }}"><span class="for-screen-reader">Role: {{ $u->role === 1 ? 'Administrator' : 'Member' }}</span><img src="{{ app(\App\Support\Assets::class)->path('crown.svg') }}" width="20" height="20" aria-hidden="true"><input type="checkbox" hidden name="user[role]" value="administrator" id="role_user_{{ $u->id }}" data-action="form#submit" @checked($u->role === 1) @disabled($u->id === $currentUser->id)></label>
</form>
@if($u->id !== $currentUser->id)<form action="/account/users/{{ $u->id }}" method="post"> @method('DELETE')<button type="submit" class="btn btn--negative txt-small" data-turbo-confirm="Are you sure you want to permanently remove this person from the account? This can’t be undone.">Delete {{ $u->name }}</button></form>@endif
@endif
@if($u->id === $currentUser->id)<a href="/users/me/profile" class="btn txt-small">My settings</a>@endif
</li>
@endforeach
</menu>
</section>
@endsection
