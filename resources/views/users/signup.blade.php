@extends('layouts.app', ['title' => 'Sign up', 'bodyClass' => 'signup'])
@section('content')
@php($assets = app(\App\Support\Assets::class))
<form action="{{ $action }}" method="post" enctype="multipart/form-data" class="center">
<section class="nametag u-relative"><div class="nametag__inner flex flex-column gap">
<h1>Welcome to Campfire</h1>
<label class="align-center center avatar__form gap" data-controller="upload-preview"><div class="btn input--file"><img src="{{ $assets->path('camera.svg') }}" aria-hidden="true"><input type="file" name="user[avatar]" accept="image/*" data-upload-preview-target="input" data-action="upload-preview#previewImage"><span class="for-screen-reader">Upload avatar</span></div><div class="btn avatar input--file"><img src="{{ $assets->path('default-avatar.svg') }}" data-upload-preview-target="image" aria-hidden="true"></div></label>
<label>Your name<input class="input" name="user[name]" autocomplete="name" required></label>
<label>Email address<input class="input" name="user[email_address]" type="email" autocomplete="username" required></label>
<label>Password<input class="input" name="user[password]" type="password" autocomplete="new-password" maxlength="72" required></label>
<button type="submit" class="btn btn--reversed">Create account</button>
</div></section></form>
@endsection
