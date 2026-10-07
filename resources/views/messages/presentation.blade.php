<div id="presentation_message_{{ $message->client_message_id }}" dir="auto" data-reply-target="body" data-messages-target="body">@if($message->attachment?->blob)
@php($blob = $message->attachment->blob)
@php($storage = app(\App\Support\BlobStorage::class))
@php($url = $storage->url($blob))
@if(str_starts_with($blob->content_type ?? '', 'video/'))
<video src="{{ $url }}" poster="{{ $storage->representationUrl($blob, ['resize_to_limit' => [1200, 800], 'format' => 'webp']) }}" controls preload="none" class="message__attachment"></video>
@elseif(str_starts_with($blob->content_type ?? '', 'image/') || $blob->content_type === 'application/pdf')
<a href="{{ $url }}" class="flex" data-lightbox-target="image" data-action="lightbox#open" data-lightbox-url-value="{{ $url }}?disposition=attachment"><img src="{{ $storage->representationUrl($blob, ['resize_to_limit' => [1200, 800], 'format' => $storage->thumbnailFormat($blob)]) }}" alt="{{ $blob->filename }}" class="message__attachment" loading="lazy"></a>
@else
<a href="{{ $url }}?disposition=attachment">{{ $blob->filename }}</a>
@endif
 @elseif($sound = $message->sound())    <div class="sound" data-controller="sound" data-action="messages:play->sound#play" data-sound-url-value="{{ app(\App\Support\Assets::class)->path($sound['asset']) }}"><button class="btn btn--plain" data-action="sound#play">🔊</button>@if($sound['image'])<img src="{{ app(\App\Support\Assets::class)->path($sound['image']['asset']) }}" width="{{ $sound['image']['width'] }}" height="{{ $sound['image']['height'] }}">@else {{ $sound['text'] }} @endif</div> @else    {!! app(\App\Support\RichTextRenderer::class)->html($message->richText?->body ?? '') !!}  @endif</div>
