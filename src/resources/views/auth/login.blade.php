<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — CRM</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f6f8; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .box { background: #fff; border: 1px solid #e3e3e0; border-radius: 12px; padding: 2rem; width: 100%; max-width: 380px; }
        h1 { font-size: 1.15rem; margin-bottom: 1.4rem; text-align: center; }
        label { display: block; font-size: .8rem; font-weight: 600; margin-top: .9rem; color: #444; }
        input { width: 100%; padding: .55rem .6rem; border: 1px solid #d5d5d2; border-radius: 7px; font-size: .88rem; margin-top: .25rem; }
        button { width: 100%; margin-top: 1.3rem; background: #1a73e8; color: #fff; border: 0; padding: .6rem; border-radius: 7px; font-size: .88rem; cursor: pointer; }
        .alert { background: #fce8e6; color: #c5221f; padding: .6rem .8rem; border-radius: 8px; font-size: .82rem; margin-bottom: 1rem; }
        .alert ul { padding-left: 1.1rem; }
        .links { text-align: center; margin-top: 1rem; font-size: .8rem; }
        .links a { color: #1a73e8; text-decoration: none; }
    </style>
</head>
<body>
<div class="box">
    <h1>CRM — вход</h1>

    @if ($errors->any())
        <div class="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>

        <label for="password">Пароль</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Войти</button>
    </form>
    <div class="links"><a href="{{ route('password.request') }}">Забыли пароль?</a></div>
</div>
</body>
</html>
