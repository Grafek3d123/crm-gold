@extends('layouts.app')

@section('title', 'Клиенты — CRM')

@section('content')
    <h1 style="font-size: 1.3rem; margin-bottom: 1rem;">Клиенты</h1>

    <div class="card">
        <form method="GET" action="{{ route('customers.index') }}">
            <div class="row">
                <input type="text" name="q" value="{{ $search }}" placeholder="Поиск по имени, телефону или email" style="flex: 1; min-width: 240px;">
                <button type="submit" class="btn btn-secondary">Найти</button>
                @if ($search)
                    <a class="btn btn-secondary" href="{{ route('customers.index') }}">Сбросить</a>
                @endif
            </div>
        </form>
    </div>

    <div class="card">
        @if ($customers->isNotEmpty())
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Имя</th>
                        <th>Телефон</th>
                        <th>Email</th>
                        <th>Обращений</th>
                        <th>Касаний</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $customer)
                        <tr>
                            <td><a href="{{ route('customers.show', $customer) }}">#{{ $customer->id }}</a></td>
                            <td>{{ $customer->name ?: '—' }}</td>
                            <td>{{ $customer->phone_raw ?: '—' }}</td>
                            <td>{{ $customer->email_raw ?: '—' }}</td>
                            <td>{{ $customer->inquiries_count }}</td>
                            <td>{{ $customer->touchpoints_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="pager">
                {{ $customers->links() }}
            </div>
        @else
            <div class="empty">{{ $search ? 'Ничего не найдено' : 'Клиентов пока нет' }}</div>
        @endif
    </div>
@endsection
