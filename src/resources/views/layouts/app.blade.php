<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'CRM')</title>
    @vite('resources/css/app.css')
    @livewireStyles
</head>
<body class="bg-gray-50 min-h-screen">
@auth
<header class="bg-gray-900 text-white px-6 py-4 flex justify-between items-center">
    <h1 class="text-lg font-semibold">CRM — Золото Петербурга</h1>
    <nav class="flex gap-4">
        <a href="{{ route('dashboard') }}" class="text-sm hover:underline">Дашборд</a>
        <a href="{{ route('inquiries.index') }}" class="text-sm hover:underline">Обращения</a>
        <a href="{{ route('customers.index') }}" class="text-sm hover:underline">Клиенты</a>
        <a href="#" onclick="event.preventDefault();document.getElementById('logout-form').submit();" class="text-sm hover:underline">Выход</a>
    </nav>
</header>
<form id="logout-form" action="{{ route('logout') }}" method="POST" class="inline">@csrf</form>
@endauth

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    @if (session('status'))
        <div class="mb-4 p-3 rounded-lg bg-green-50 text-green-700 text-sm">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>

<footer class="text-center py-6 text-gray-400 text-xs">CRM · Laravel 13 · PostgreSQL</footer>

@livewireScripts
@vite('resources/js/app.js')
</body>
</html>