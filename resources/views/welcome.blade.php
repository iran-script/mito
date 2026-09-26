<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Welcome') }}</title>
    <link rel="stylesheet" href="{{ asset('css/persian-admin.css') }}">
    <style>body{margin:0;background:#f5f7fb;color:#172033}main{max-width:42rem;margin:12vh auto;padding:2rem}h1{font-size:2rem}a{display:inline-block;margin-block-start:1rem;padding:.75rem 1.25rem;border-radius:.6rem;background:#172033;color:white;text-decoration:none}a:focus-visible{outline:3px solid #b77900;outline-offset:4px}</style>
</head>
<body>
    <main>
        <h1>{{ __('Welcome') }}</h1>
        <p>{{ __('A place for conversations, shared interests, and games.') }}</p>
        <a href="{{ route('filament.admin.auth.login') }}">{{ __('Open administration') }}</a>
    </main>
</body>
</html>
