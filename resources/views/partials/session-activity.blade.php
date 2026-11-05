@auth
<meta name="session-activity-url" content="{{ route('session.activity') }}">
<meta name="session-status-url" content="{{ route('session.status') }}">
<meta name="session-login-url" content="{{ route('login') }}">
<meta name="session-context" content="{{ json_encode(app(\App\Services\SessionActivity::class)->snapshot(request())) }}">
@endauth
