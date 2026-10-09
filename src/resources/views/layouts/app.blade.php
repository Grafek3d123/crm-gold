<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'CRM')</title>
    @vite('resources/css/app.css')
    @livewireStyles
</head>
<body>
@auth
<header>
    <h1>CRM — Золото Петербурга</h1>
    <nav>
        <a href="{{ route('dashboard') }}">Дашборд</a>
        <a href="{{ route('inquiries.index') }}">Обращения</a>
        <a href="{{ route('customers.index') }}">Клиенты</a>
        <a href="#" onclick="event.preventDefault();document.getElementById('logout-form').submit();">Выход</a>
    </nav>
</header>
<form id="logout-form" action="{{ route('logout') }}" method="POST" class="inline">@csrf</form>
@endauth

<div class="container">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>

<footer>CRM · Laravel 13 · PostgreSQL</footer>

@livewireScripts
@vite('resources/js/app.js')
<script defer src="{{ asset('vendor/livewire/livewire.js') }}"></script>
</body>
</html>