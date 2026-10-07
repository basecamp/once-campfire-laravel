@php
$createdAt = $message->created_at;
$timestamp = $createdAt->getTimestampMs();
$dom = 'message_'.$message->client_message_id;
@endphp
<div id="{{ $dom }}" class="message" data-controller="reply" data-user-id="{{ $message->creator_id }}" data-message-id="{{ $message->id }}" data-message-timestamp="{{ $timestamp }}" data-message-updated-at="{{ $message->updated_at->getTimestampMs() }}" data-sort-value="{{ $timestamp }}" data-messages-target="message" data-search-results-target="message" data-refresh-room-target="message" data-reply-composer-outlet="#composer">
<h2 class="message__day-separator"><time datetime="{{ $createdAt->toISOString() }}" data-local-time-target="date">{{ $createdAt->format('F j, Y') }}</time></h2>
<figure class="avatar message__avatar"><a title="{{ $message->creator->name }}" class="btn avatar" data-turbo-frame="_top" href="/users/{{ $message->creator_id }}"><img src="{{ $message->creator->avatarUrl() }}" width="48" height="48" aria-hidden="true"></a></figure>
<turbo-frame id="edit_{{ $dom }}">@include('messages.content')</turbo-frame></div>
