@php($assets = app(\App\Support\Assets::class))
<!DOCTYPE html>
<html>
<head>
<title>{{ $title ?? 'Campfire' }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no, interactive-widget=resizes-content">
<meta name="view-transition" content="same-origin"><meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)"><meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="action-cable-url" content="/cable">
@if(isset($currentUser))
<meta name="current-user-id" content="{{ $currentUser->id }}"><meta name="current-user-name" content="{{ $currentUser->name }}">
@endif
<meta name="vapid-public-key" content="{{ config('campfire.vapid_public_key', '') }}"><meta name="turbo-prefetch" content="true">
<link rel="manifest" href="/webmanifest.json"><link rel="icon" href="/account/logo" type="image/png"><link rel="apple-touch-icon" href="/account/logo">
{!! $assets->head() !!}
@php($customStyles = \Illuminate\Support\Facades\DB::table('accounts')->value('custom_styles'))
@if($customStyles)<style>{!! $customStyles !!}</style>@endif
@yield('head')
</head>
<body class="{{ $bodyClass ?? '' }}" data-controller="local-time lightbox">
<a href="#main-content" class="skip-navigation btn">Skip to main content</a>
<nav id="nav">@yield('nav')</nav>
@if(session('notice') || session('alert'))
<div class="flash" data-controller="element-removal" data-action="animationend->element-removal#remove"><div class="flash__inner shadow"><img src="{{ $assets->path(session('alert') ? 'alert.svg' : 'check.svg') }}" class="colorize--white" aria-hidden="true" width="24" height="24"></div><span class="for-screen-reader" role="alert" aria-atomic="true">{{ session('alert') ?? session('notice') }}</span></div>
@endif
<main id="main-content">@yield('content')<footer id="footer">@yield('footer')</footer></main>
<aside id="sidebar" data-controller="toggle-class" data-toggle-class-toggle-class="open">@yield('sidebar')</aside>
<dialog class="lightbox" aria-label="Image Viewer (Press escape to close)" data-lightbox-target="dialog" data-action="close->lightbox#reset">
<img src="" class="lightbox__image" data-lightbox-target="zoomedImage">
<form method="dialog" class="lightbox__btn"><button class="btn"><img src="{{ $assets->path('remove.svg') }}" aria-hidden="true"><span class="for-screen-reader">Close image viewer</span></button></form>
<a href="" class="lightbox__btn--download btn hide-in-ios-pwa" data-lightbox-target="download"><img src="{{ $assets->path('download.svg') }}" aria-hidden="true"><span class="for-screen-reader">Download file</span></a>
<button class="lightbox__btn--share btn" data-controller="web-share" data-action="web-share#share" data-web-share-files-value="" data-lightbox-target="share"><img src="{{ $assets->path('share.svg') }}" aria-hidden="true"><span class="for-screen-reader">Share file</span></button>
</dialog>
<a href="https://once.com" id="app-logo" target="_blank" aria-label="Once software from 37signals home page"><img src="{{ $assets->path('campfire-icon.png') }}" alt="Campfire logo" width="256" height="216"></a>
</body>
</html>
