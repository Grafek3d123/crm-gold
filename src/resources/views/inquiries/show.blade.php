@extends('layouts.app')

@section('title', 'Обращение #'.$inquiry->id.' — CRM')

@section('content')
    <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
        <h1 style="font-size: 1.3rem;">Обращение #{{ $inquiry->id }}</h1>
        <a class="btn btn-secondary" href="{{ route('inquiries.index') }}">К списку</a>
    </div>

    <div class="card">
        <h2>Карточка</h2>
        <dl class="kv">
            <dt>Клиент</dt>
            <dd>
                @if ($inquiry->customer)
                    <a href="{{ route('customers.show', $inquiry->customer) }}">{{ $inquiry->customer->name ?: 'без имени' }}</a>
                @else — @endif
            </dd>
            <dt>Телефон</dt>
            <dd>{{ $inquiry->phone_raw ?: '—' }}</dd>
            <dt>Email</dt>
            <dd>{{ $inquiry->email_raw ?: '—' }}</dd>
            <dt>Сайт</dt>
            <dd>{{ $inquiry->site?->domain ?: '— (вручную)' }}</dd>
            <dt>Источник</dt>
            <dd>{{ $inquiry->source_kind }}</dd>
            <dt>Статус</dt>
            <dd><span class="badge {{ $inquiry->status->value }}">{{ $inquiry->status->label() }}</span></dd>
            <dt>Сообщение</dt>
            <dd>{{ $inquiry->message ?: '—' }}</dd>
            <dt>Создано</dt>
            <dd>{{ $inquiry->created_at?->format('d.m.Y H:i') }}</dd>
            @if ($inquiry->landing_path)
                <dt>Посадочная</dt>
                <dd>{{ $inquiry->landing_path }}</dd>
            @endif
        </dl>
    </div>

    <div class="card">
        <h2>Смена статуса</h2>
        @if (count($transitions) > 0)
            <form method="POST" action="{{ route('inquiries.status', $inquiry) }}">
                @csrf
                <div class="row">
                    <select name="status" style="width: auto;">
                        @foreach ($transitions as $to)
                            <option value="{{ $to->value }}">{{ $to->label() }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="note" placeholder="Комментарий (необязательно)" style="width: 260px;">
                    <button type="submit">Применить</button>
                </div>
            </form>
        @else
            <p class="muted">Обращение в терминальном статусе — переходы недоступны.</p>
        @endif
    </div>

    <div class="card">
        <h2>Активности</h2>
        @if ($inquiry->activities->isNotEmpty())
            <table>
                <thead>
                    <tr><th>Время</th><th>Тип</th><th>Канал</th><th>Примечание</th></tr>
                </thead>
                <tbody>
                    @foreach ($inquiry->activities as $activity)
                        <tr>
                            <td>{{ $activity->happened_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $activity->type }}</td>
                            <td>{{ $activity->channel ?: '—' }}</td>
                            <td>{{ $activity->note ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty">Активностей нет</div>
        @endif
    </div>

    <div class="card">
        <h2>История статусов</h2>
        <table>
            <thead>
                <tr><th>Дата</th><th>Было</th><th>Стало</th><th>Кто</th><th>Комментарий</th></tr>
            </thead>
            <tbody>
                @foreach ($inquiry->statusHistory as $row)
                    <tr>
                        <td>{{ $row->created_at?->format('d.m.Y H:i') }}</td>
                        <td>{{ $row->from_status ?: '—' }}</td>
                        <td>{{ $row->to_status }}</td>
                        <td>{{ $row->changedBy?->name ?: 'система' }}</td>
                        <td>{{ $row->note ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Attribution history</h2>
        @if ($inquiry->touchpoints->isNotEmpty())
            <table>
                <thead>
                    <tr><th>Время</th><th>Источник</th><th>Страница</th><th>UTM source</th></tr>
                </thead>
                <tbody>
                    @foreach ($inquiry->touchpoints as $tp)
                        <tr>
                            <td>{{ $tp->touched_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $tp->source_kind }}</td>
                            <td>{{ $tp->path ?: '—' }}</td>
                            <td>{{ $tp->utm_source ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty">История касаний отсутствует</div>
        @endif
    </div>
@endsection
