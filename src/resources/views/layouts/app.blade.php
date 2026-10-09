<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'CRM')</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f6f8; color: #1b1b18; }
        header { background: #1a1a2e; color: #fff; padding: .9rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        header h1 { font-size: 1.15rem; font-weight: 600; }
        header nav a { color: #fff; text-decoration: none; margin-left: 1.2rem; font-size: .88rem; opacity: .85; }
        header nav a:hover { opacity: 1; }
        .container { max-width: 1100px; margin: 1.8rem auto; padding: 0 1rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.6rem; }
        .stat { background: #fff; border: 1px solid #e3e3e0; border-radius: 10px; padding: 1.3rem; }
        .stat .num { font-size: 1.9rem; font-weight: 700; color: #1a73e8; }
        .stat .label { font-size: .82rem; color: #706f6c; margin-top: .2rem; }
        .card { background: #fff; border: 1px solid #e3e3e0; border-radius: 10px; padding: 1.4rem; margin-bottom: 1rem; }
        .card h2 { font-size: 1.05rem; margin-bottom: .9rem; }
        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        th, td { text-align: left; padding: .55rem .5rem; border-bottom: 1px solid #eee; }
        th { color: #706f6c; font-weight: 600; font-size: .78rem; text-transform: uppercase; }
        tr:hover { background: #fafafa; }
        .badge { display: inline-block; padding: .18rem .5rem; border-radius: 999px; font-size: .7rem; font-weight: 600; }
        .badge.new, .badge.qualified { background: #e6f4ea; color: #137333; }
        .badge.in_progress { background: #fef7e0; color: #e68a00; }
        .badge.rejected, .badge.duplicate { background: #fce8e6; color: #c5221f; }
        .badge.converted { background: #e8f0fe; color: #1a73e8; }
        .badge.archived { background: #f1f3f4; color: #5f6368; }
        form.inline { display: inline; }
        input[type=text], input[type=email], input[type=password], select, textarea {
            width: 100%; padding: .5rem .6rem; border: 1px solid #d5d5d2; border-radius: 7px; font-size: .88rem; margin-top: .2rem; font-family: inherit;
        }
        textarea { min-height: 90px; resize: vertical; }
        label { display: block; font-size: .82rem; font-weight: 600; margin-top: .8rem; color: #444; }
        button, .btn { background: #1a73e8; color: #fff; border: 0; padding: .5rem 1rem; border-radius: 7px; font-size: .85rem; cursor: pointer; text-decoration: none; display: inline-block; }
        button:hover, .btn:hover { background: #1765cc; }
        .btn-secondary { background: #eef0f3; color: #1b1b18; }
        .btn-secondary:hover { background: #e2e5ea; }
        .row { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
        .muted { color: #8a8a86; font-size: .82rem; }
        .alert { padding: .7rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: .85rem; }
        .alert-success { background: #e6f4ea; color: #137333; }
        .alert-error { background: #fce8e6; color: #c5221f; }
        .pager { display: flex; gap: .4rem; margin-top: 1rem; }
        footer { text-align: center; padding: 1.6rem; color: #a0a0a0; font-size: .76rem; }
        .empty { text-align: center; color: #999; padding: 2.2rem; font-size: .88rem; }
        .kv { display: grid; grid-template-columns: 160px 1fr; gap: .35rem .8rem; font-size: .86rem; }
        .kv dt { color: #706f6c; }
        .kv dd { word-break: break-word; }
        .field { margin-bottom: .6rem; }
    </style>
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
</body>
</html>
