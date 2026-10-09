@extends('layouts.app')

@section('title', 'Клиент #'.$customer->id.' — CRM')

@section('content')
    <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
        <h1 style="font-size: 1.3rem;">Клиент #{{ $customer->id }}</h1>
        <a class="btn btn-secondary" href="{{ route('customers.index') }}">К списку</a>
    </div>

    <div class="card">
        <h2>Контакты</h2>
        <dl class="kv">
            <dt>Имя</dt>
            <dd>{{ $customer->name ?: '—' }}</dd>
            <dt>Телефон</dt>
            <dd>{{ $customer->phone_raw ?: '—' }} <span class="muted">(норм.: {{ $customer->phone_normalized ?: '—' }})</span></dd>
            <dt>Email</dt>
            <dd>{{ $customer->email_raw ?: '—' }} <span class="muted">(норм.: {{ $customer->email_normalized ?: '—' }})</span></dd>
            <dt>Комментарий</dt>
            <dd>{{ $customer->comment ?: '—' }}</dd>
            <dt>Создан</dt>
            <dd>{{ $customer->created_at?->format('d.m.Y H:i') }}</dd>
        </dl>
    </div>

    <div class="card">
        <h2>Обращения</h2>
        @if ($customer->inquiries->isNotEmpty())
            <table>
                <thead>
                    <tr><th>ID</th><th>Источник</th><th>Статус</th><th>Создано</th></tr>
                </thead>
                <tbody>
                    @foreach ($customer->inquiries as $inquiry)
                        <tr>
                            <td><a href="{{ route('inquiries.show', $inquiry) }}">#{{ $inquiry->id }}</a></td>
                            <td>{{ $inquiry->source_kind }}</td>
                            <td><span class="badge {{ $inquiry->status->value }}">{{ $inquiry->status->label() }}</span></td>
                            <td>{{ $inquiry->created_at?->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty">Обращений нет</div>
        @endif
    </div>

    @if (auth()->user()?->isAdmin())
    <div class="card">
        <h2>Объединить с другой карточкой</h2>
        <form method="POST" action="{{ route('customers.merge', $customer) }}">
            @csrf
            <div class="row">
                <input type="text" name="loser_id" placeholder="ID дубля" style="width: 130px;" required>
                <input type="text" name="reason" placeholder="Причина объединения" style="flex: 1; min-width: 220px;" required>
                <button type="submit" class="btn btn-secondary">Объединить</button>
            </div>
        </form>
        <p class="muted" style="margin-top: .6rem;">
            Связи дубля переносятся в эту карточку, дубль помечается как объединённый.
            Доступно только администратору.
        </p>
    </div>
    @endif

    <div class="card">
        <h2>Attribution history</h2>
        @if ($customer->touchpoints->isNotEmpty())
            <table>
                <thead>
                    <tr><th>Время</th><th>Источник</th><th>UTM source</th><th>Кампания</th></tr>
                </thead>
                <tbody>
                    @foreach ($customer->touchpoints as $tp)
                        <tr>
                            <td>{{ $tp->touched_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $tp->source_kind }}</td>
                            <td>{{ $tp->utm_source ?: '—' }}</td>
                            <td>{{ $tp->utm_campaign ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty">История касаний отсутствует</div>
        @endif
    </div>
@endsection
