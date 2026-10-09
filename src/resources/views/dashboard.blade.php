@extends('layouts.app')

@section('title', 'Дашборд — CRM')

@section('content')
    <div class="grid">
        <div class="stat">
            <div class="num">{{ $inquiryCount }}</div>
            <div class="label">Обращений всего</div>
        </div>
        <div class="stat">
            <div class="num">{{ $newCount }}</div>
            <div class="label">Новых обращений</div>
        </div>
        <div class="stat">
            <div class="num">{{ $customerCount }}</div>
            <div class="label">Клиентов</div>
        </div>
    </div>

    <div class="card">
        <div class="row" style="justify-content: space-between; margin-bottom: .9rem;">
            <h2 style="margin: 0;">Последние обращения</h2>
            <a class="btn" href="{{ route('inquiries.create') }}">Зарегистрировать обращение</a>
        </div>

        @if ($recentInquiries->isNotEmpty())
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Клиент</th>
                        <th>Телефон</th>
                        <th>Источник</th>
                        <th>Статус</th>
                        <th>Создано</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentInquiries as $inquiry)
                        <tr>
                            <td><a href="{{ route('inquiries.show', $inquiry) }}">#{{ $inquiry->id }}</a></td>
                            <td>{{ $inquiry->customer?->name ?: '—' }}</td>
                            <td>{{ $inquiry->phone_raw ?: '—' }}</td>
                            <td>{{ $inquiry->source_kind }}</td>
                            <td><span class="badge {{ $inquiry->status->value }}">{{ $inquiry->status->label() }}</span></td>
                            <td>{{ $inquiry->created_at?->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty">Обращений пока нет</div>
        @endif
    </div>
@endsection
