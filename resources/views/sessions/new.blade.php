@extends('layouts.app')
@section('content')
<section class="panel center"><h1>Sign in to Campfire</h1>@if($error ?? false)<p role="alert">Too many requests or unauthorized.</p>@endif
<form method="post" action="/session"><label>Email address<input class="input" type="email" name="email_address" required autocomplete="username"></label><label>Password<input class="input" type="password" name="password" required autocomplete="current-password"></label><button class="btn btn--reversed">Sign in</button></form></section>
@endsection
