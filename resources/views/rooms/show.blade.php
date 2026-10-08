@extends('layouts.app',['title'=>$room->displayName($currentUser),'bodyClass'=>'sidebar'])
@section('head')<meta name="current-room-id" content="{{ $room->id }}"><meta name="turbo-cache-control" content="no-preview">@endsection
@section('nav')
<a class="btn" href="/users/me/profile"><img class="avatar" src="{{ $currentUser->avatarUrl() }}" width="32" height="32" alt="My settings"></a>
<h1 class="overflow-ellipsis">@if($room->type === 'Rooms::Direct')<span class="for-screen-reader">Ping with </span>@endif{{ $room->displayName($currentUser) }}</h1>
<div class="flex-item-justify-end"><a class="btn" href="/rooms/{{ $room->id }}/settings" ><img src="{{ app(\App\Support\Assets::class)->path('settings.svg') }}" width="20" height="20" aria-hidden="true"><span class="for-screen-reader">Room settings</span></a><turbo-frame id="involvement_{{ $room->id }}" src="/rooms/{{ $room->id }}/involvement"></turbo-frame></div>
@endsection
@section('sidebar')<turbo-frame id="user_sidebar" src="/users/me/sidebar" target="_top" data-turbo-permanent="true" data-controller="rooms-list read-rooms turbo-frame" data-rooms-list-unread-class="unread" data-action="presence:present@window->rooms-list#read read-rooms:read->rooms-list#read turbo:frame-load->rooms-list#loaded refresh-room:visible@window->turbo-frame#reload"></turbo-frame>@endsection
@section('content')
@php($assets = app(\App\Support\Assets::class))
<div id="message-area" class="message-area" contents data-controller="messages presence drop-target" data-action="turbo:before-stream-render@document->messages#beforeStreamRender keydown.up@document->messages#editMyLastMessage dragenter->drop-target#dragenter dragover->drop-target#dragover drop->drop-target#drop visibilitychange@document->presence#visibilityChanged" data-messages-first-of-day-class="message--first-of-day" data-messages-formatted-class="message--formatted" data-messages-me-class="message--me" data-messages-mentioned-class="message--mentioned" data-messages-threaded-class="message--threaded" data-messages-page-url-value="{{ url('/rooms/'.$room->id.'/messages') }}">
<script type="text/template" data-messages-target="template">
<div class="message message--me $messageClasses$" id="message_$clientMessageId$" data-format-message-target="message" data-user-id="{{ $currentUser->id }}" data-message-timestamp="$messageTimestamp$" data-messages-target="message">
<div class="message__day-separator"><time class="message__timestamp" datetime="$messageDatetime$" data-local-time-target="date"></time></div>
<figure class="avatar message__avatar"><a class="btn avatar" data-turbo-frame="_top" href="/users/{{ $currentUser->id }}"><img src="{{ $currentUser->avatarUrl() }}" width="48" height="48" aria-hidden="true"></a></figure>
<div class="message__body"><div class="message__body-content"><div class="message__meta"><h3 class="message__heading"><span class="message__author"><strong>{{ $currentUser->name }}</strong></span><span class="message__permalink"><time class="message__timestamp" datetime="$messageDatetime$" data-local-time-target="time"></time></span></h3><div class="message__actions"><div class="position-relative"><span class="btn message__action-btn message__options-btn"><img src="{{ $assets->path('menu-dots-horizontal.svg') }}" class="colorize--black" aria-hidden="true"><span class="for-screen-reader">Message options</span></span></div></div></div>$body$</div></div></div>
</script>
<div id="messages_room_{{ $room->id }}" class="messages" data-controller="maintain-scroll refresh-room" data-action="turbo:before-stream-render@document->maintain-scroll#beforeStreamRender visibilitychange@document->refresh-room#visibilityChanged online@window->refresh-room#online" data-messages-target="messages" data-refresh-room-loaded-at-value="{{ $room->updated_at->getTimestampMs() }}" data-refresh-room-url-value="/rooms/{{ $room->id }}/refresh">@include('messages.index')</div>
<turbo-cable-stream-source channel="RoomMessagesChannel" signed-stream-name="{{ app(\App\Support\RailsCrypto::class)->stream($room->id,$room->type) }}"></turbo-cable-stream-source>
<button class="message-area__return-to-latest btn" hidden data-action="messages#returnToLatest" data-messages-target="latest"><img src="{{ $assets->path('arrow-down.svg') }}" width="20" height="20" aria-hidden="true"><span class="for-screen-reader">Jump to newest message</span></button>
</div>
@endsection
@section('footer')
@php($assets = app(\App\Support\Assets::class))
<div class="composer flex align-end gap position-relative" data-controller="typing-notifications" data-typing-notifications-active-class="typing-indicator--active">
<a href="/searches" class="btn flex-item-no-shrink margin-block-end composer__context-btn" style="view-transition-name: input-switcher"><img src="{{ $assets->path('search.svg') }}" width="20" height="20" aria-hidden="true"><span class="for-screen-reader">Search</span></a>
<turbo-frame id="composer-frame">
<form id="composer" action="/rooms/{{ $room->id }}/messages" method="post" class="margin-block flex-item-grow contain" data-controller="composer drop-target" data-action="dragenter->drop-target#dragenter dragover->drop-target#dragover drop->drop-target#drop drop-target:drop@window->composer#dropFiles lexxy:file-accept->composer#preventAttachment refresh-room:online@window->composer#online typing-notifications#stop paste->composer#pasteFiles turbo:submit-end->composer#submitEnd refresh-room:offline@window->composer#offline" data-composer-messages-outlet="#message-area" data-composer-toolbar-class="composer--rich-text" data-composer-room-id-value="{{ $room->id }}">

<fieldset data-composer-target="fields" contents><div class="flex flex-column"><div class="composer__filelist flex flex--align-center gap flex-wrap" data-composer-target="fileList"></div>
<div class="flex composer__input input input--actor fill-white min-width" style="--input-border-radius: 1.3rem"><div class="flex align-end gap full-width">
<img src="{{ $assets->path('messages-outlined.svg') }}" class="composer__input-hint colorize--black" width="22" height="22" aria-hidden="true" style="view-transition-name: input-btn;">
<div class="flex flex-column flex-item-grow min-width gap">
<lexxy-editor name="message[body]" id="message_body" rows="1" class="input lexxy-content" style="order: -1" aria-multiline="true" aria-label="Write a message" permitted-attachment-types="application/vnd.campfire.mention application/vnd.actiontext.opengraph-embed" data-controller="unfurl" data-composer-target="text" data-action="lexxy:change->typing-notifications#start keydown->composer#submitByKeyboard:capture lexxy:change->composer#saveDraft lexxy:insert-link->unfurl#unfurl" data-direct-upload-url="/rails/active_storage/direct_uploads" data-blob-url-template="/rails/active_storage/blobs/redirect/:signed_id/:filename">
<lexxy-prompt trigger="@" name="mention" src="/autocompletable/users?room_id={{ $room->id }}" remote-filtering="true" empty-results="No matches"></lexxy-prompt>
</lexxy-editor></div>
<label class="btn btn--borderless txt-small flex-item-no-shrink composer__attachment-btn input--file"><img src="{{ $assets->path('attachment.svg') }}" class="colorize--black" width="22" height="22" aria-hidden="true"><input type="file" data-action="composer#filePicked" multiple><span class="for-screen-reader">Attach a file</span></label>
<button class="btn btn--borderless txt-small flex-item-no-shrink composer__rich-text-btn" type="button" data-action="composer#toggleToolbar"><img src="{{ $assets->path('text-options.svg') }}" width="20" height="20" aria-hidden="true"><span class="for-screen-reader">Rich text</span></button>
<button name="send" type="submit" data-action="composer#submit" class="btn btn--reversed flex-item-no-shrink txt-small"><img src="{{ $assets->path('arrow-up.svg') }}" width="20" height="20" aria-hidden="true"><span class="for-screen-reader">Send Message</span></button>
</div></div></div></fieldset>
<div class="typing-indicator gap txt-small align-center flex-inline" data-typing-notifications-target="indicator"><div class="typing-indicator__author spinner" data-typing-notifications-target="author"></div></div>
<input type="hidden" name="message[client_message_id]" data-composer-target="clientid">
</form></turbo-frame></div>
@endsection
