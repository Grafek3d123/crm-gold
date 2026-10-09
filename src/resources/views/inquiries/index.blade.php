@extends('layouts.app')

@section('title', 'Обращения — CRM')

@section('content')
    <livewire:inquiry-create-modal />

    <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
        <h1 style="font-size: 1.3rem;">Обращения</h1>
        <button class="btn" wire:click="$dispatch('open-inquiry-create-modal')">Зарегистрировать обращение</button>
    </div>

    <div class="card">
        @if ($inquiries->isNotEmpty())
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Клиент</th>
                        <th>Телефон</th>
                        <th>Сайт</th>
                        <th>Источник</th>
                        <th>Статус</th>
                        <th>Создано</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($inquiries as $inquiry)
                        <tr>
                            <td><a href="{{ route('inquiries.show', $inquiry) }}">#{{ $inquiry->id }}</a></td>
                            <td>{{ $inquiry->customer?->name ?: '—' }}</td>
                            <td>{{ $inquiry->phone_raw ?: '—' }}</td>
                            <td>{{ $inquiry->site?->domain ?: '—' }}</td>
                            <td>{{ $inquiry->source_kind }}</td>
                            <td><span class="badge {{ $inquiry->status->value }}">{{ $inquiry->status->label() }}</span></td>
                            <td>{{ $inquiry->created_at?->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="pager">
                {{ $inquiries->links() }}
            </div>
        @else
            <div class="empty">Обращений пока нет</div>
        @endif
    </div>
@endsection