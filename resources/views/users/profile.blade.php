@extends('layouts.app', ['title' => $user->name])
@section('nav')
<a href="/" class="btn">Back to chat</a>
<form action="/session" method="post" data-controller="sessions" class="flex-item-justify-end"> @method('DELETE')<input type="hidden" name="push_subscription_endpoint" data-sessions-target="pushSubscriptionEndpoint"><button class="btn" type="submit" data-action="sessions#logout:prevent">Log out</button></form>
@endsection
@section('content')
<section class="panel flex flex-column gap" style="view-transition-name: avatar-{{ $user->id }}">
<div class="align-center center avatar__form gap" data-controller="upload-preview">
<form action="/users/me/profile" method="post" enctype="multipart/form-data" data-controller="form"> @method('PATCH')
<label class="btn input--file"><input type="file" name="user[avatar]" accept="image/*" data-upload-preview-target="input" data-action="upload-preview#previewImage change->form#submit"><span>Upload avatar</span></label>
<img src="{{ $user->avatarUrl() }}" width="300" height="300" data-upload-preview-target="image" alt="Your avatar">
</form>
@if(\App\Models\Attachment::where('record_type','User')->where('record_id',$user->id)->where('name','avatar')->exists())
<form action="{{ $user->avatarUrl() }}" method="post"> @method('DELETE')<button class="btn btn--negative txt-small avatar__delete-btn" type="submit">Delete avatar</button></form>
@endif
</div>
<form action="/users/me/profile" method="post" class="flex flex-column gap"> @method('PATCH')
<label><span class="for-screen-reader">Name</span><input class="input txt-large full-width" name="user[name]" value="{{ $user->name }}" required autofocus autocomplete="name" placeholder="Enter your name"></label>
<label><span class="for-screen-reader">Email address</span><input class="input txt-large full-width" type="email" name="user[email_address]" value="{{ $user->email_address }}" autocomplete="username" placeholder="Enter your email address"></label>
<label><span class="for-screen-reader">Change password</span><input class="input txt-large full-width" type="password" name="user[password]" autocomplete="new-password" maxlength="72" placeholder="Change password"></label>
<label><span class="for-screen-reader">Bio</span><textarea class="input txt-large full-width" name="user[bio]" rows="3" maxlength="200" placeholder="A few words about yourself…">{{ $user->bio }}</textarea></label>
<button type="submit" class="btn btn--reversed center">Save changes</button>
</form>
<div class="margin-block pad-inline pad-block fill-shade border-radius">
<menu class="flex flex-column gap margin-none pad">
@foreach($user->memberships()->with('room.users')->get() as $membership)
<li class="flex align-center gap margin-none"><a class="flex-item-grow" href="/rooms/{{ $membership->room_id }}">{{ $membership->room->displayName($user) }}</a><form action="/rooms/{{ $membership->room_id }}/involvement" method="post" data-controller="form"> @method('PUT')<select class="input" name="involvement" data-action="change->form#submit" aria-label="Notifications for {{ $membership->room->displayName($user) }}">@foreach(['everything'=>'All messages','mentions'=>'@ mentions','nothing'=>'None','invisible'=>'Hide room'] as $value=>$text)@if($membership->room->type !== 'Rooms::Direct' || in_array($value,['everything','nothing']))<option value="{{ $value }}" @selected($membership->involvement === $value)>{{ $text }}</option>@endif @endforeach</select></form></li>
@endforeach
</menu>
</div>
<a class="btn" href="/users/me/push_subscriptions">Notifications on your devices</a>
@php($transferUrl = url('/session/transfers/'.app(\App\Support\RailsCrypto::class)->signedId($user->id, 'User', 'transfer', now()->addHours(4)->utc()->format('Y-m-d\TH:i:s.v\Z'))))
<fieldset><legend>Sign in on another device</legend><label for="session_transfer_url" class="for-screen-reader">Use this link to login automatically on another device</label><input type="text" id="session_transfer_url" class="input full-width" value="{{ $transferUrl }}" readonly><div class="flex align-center center gap"><a class="btn" href="/qr_code/{{ rtrim(strtr(base64_encode($transferUrl), '+/', '-_'), '=') }}">Show auto-login QR code</a><button class="btn" data-controller="copy-to-clipboard" data-action="copy-to-clipboard#copy" data-copy-to-clipboard-content-value="{{ $transferUrl }}" data-copy-to-clipboard-success-class="btn--success">Copy auto-login link</button><button class="btn" data-controller="web-share" data-action="web-share#share" data-web-share-title-value="Your sign-in link" data-web-share-text-value="This is your own private sign-in URL, DO NOT SHARE IT. Use it to sign-in on another device or if you get locked out." data-web-share-url-value="{{ $transferUrl }}">Share auto-login link</button></div></fieldset>
</section>
@endsection
