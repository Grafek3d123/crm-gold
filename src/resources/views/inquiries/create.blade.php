@extends('layouts.app')

@section('title', 'Новое обращение — CRM')

@section('content')
    <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
        <h1 style="font-size: 1.3rem;">Регистрация обращения</h1>
        <a class="btn btn-secondary" href="{{ route('inquiries.index') }}">К списку</a>
    </div>

    <div class="card">
        <form method="POST" action="{{ route('inquiries.store') }}">
            @csrf

            <label for="source_kind">Тип обращения</label>
            <select id="source_kind" name="source_kind">
                <option value="call">Входящий звонок</option>
                <option value="email">Письмо</option>
                <option value="other">Другое</option>
            </select>

            <label for="name">Имя клиента</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}">

            <label for="phone">Телефон</label>
            <input type="text" id="phone" name="phone" value="{{ old('phone') }}" placeholder="+7 (999) 123-45-67">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}">

            <label for="message">Текст обращения</label>
            <textarea id="message" name="message">{{ old('message') }}</textarea>

            <label for="comment">Комментарий менеджера</label>
            <input type="text" id="comment" name="comment" value="{{ old('comment') }}">

            <p class="muted" style="margin-top: .8rem;">Телефон или email обязателен хотя бы один.</p>

            <button type="submit">Зарегистрировать</button>
        </form>
    </div>
@endsection
