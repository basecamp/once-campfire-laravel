@extends('layouts.app', ['title' => $bot ? 'Edit bot' : 'New chat bot'])
@section('nav')<a href="/account/bots" class="btn">Back to chat bots</a>@endsection
@section('content')
<section class="panel">
<form action="/account/bots{{ $bot ? '/'.$bot->id : '' }}" method="post" enctype="multipart/form-data" class="flex flex-column gap"> @if($bot)@method('PATCH')@endif
<h1 class="for-screen-reader">Chat Bot Setup</h1>
<label class="align-center center avatar__form gap" data-controller="upload-preview"><div class="btn input--file"><input type="file" name="user[avatar]" accept="image/*" data-upload-preview-target="input" data-action="upload-preview#previewImage"><span>Upload bot avatar</span></div><img src="{{ $bot ? $bot->avatarUrl() : app(\App\Support\Assets::class)->path('default-bot-avatar.svg') }}" width="48" height="48" alt="Bot avatar" data-upload-preview-target="image"></label>
<label class="flex align-center gap flex-item-grow txt-large input input--actor"><span class="for-screen-reader">Name the bot</span><input class="input" name="user[name]" value="{{ $bot?->name }}" required autofocus autocomplete="name" placeholder="Name the bot"></label>
<label class="flex align-center gap flex-item-grow txt-large input input--actor"><span class="for-screen-reader">Webhook URL</span><input class="input" type="url" name="user[webhook_url]" value="{{ $webhook }}" placeholder="Webhook URL"></label>
<button class="btn btn--reversed center" type="submit">Save changes</button>
</form>
@if($bot)
<hr class="separator full-width margin-block-double"><div class="flex align-center gap justify-space-between">
<form action="/account/bots/{{ $bot->id }}" method="post"> @method('DELETE')<button type="submit" class="btn btn--negative" aria-label="Delete this chat bot" data-turbo-confirm="Are you sure you want to permanently remove this bot from the account? This can’t be undone.">Delete this chat bot</button></form>
<form action="/account/bots/{{ $bot->id }}/key" method="post"> @method('PUT')<button type="submit" class="btn btn--negative" aria-label="Generate a new key" data-turbo-confirm="Are you sure you want to change the bot key? All usage of this bot must be updated.">Generate a new key</button></form>
</div>
@endif
</section>
@endsection
