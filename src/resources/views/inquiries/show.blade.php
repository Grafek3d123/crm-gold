@extends('layouts.app')

@section('title', 'Обращение #'.$inquiry->id.' — CRM')

@section('content')
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-xl font-semibold">Обращение #{{ $inquiry->id }}</h1>
        <a href="{{ route('inquiries.index') }}" class="px-4 py-2 bg-gray-100 text-gray-900 text-sm rounded-lg hover:bg-gray-200">К списку</a>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl p-5 mb-4">
        <h2 class="text-lg font-semibold mb-3">Карточка</h2>
        <dl class="grid grid-cols-[160px_1fr] gap-y-1 gap-x-3 text-sm">
            <dt class="text-gray-500">Клиент</dt>
            <dd class="break-words">
                @if ($inquiry->customer)
                    <a href="{{ route('customers.show', $inquiry->customer) }}" class="text-blue-600 hover:underline">{{ $inquiry->customer->name ?: 'без имени' }}</a>
                @else — @endif
            </dd>
            <dt class="text-gray-500">Телефон</dt>
            <dd class="break-words">{{ $inquiry->phone_raw ?: '—' }}</dd>
            <dt class="text-gray-500">Email</dt>
            <dd class="break-words">{{ $inquiry->email_raw ?: '—' }}</dd>
            <dt class="text-gray-500">Сайт</dt>
            <dd class="break-words">{{ $inquiry->site?->domain ?: '— (вручную)' }}</dd>
            <dt class="text-gray-500">Источник</dt>
            <dd class="break-words">{{ $inquiry->source_kind }}</dd>
            <dt class="text-gray-500">Статус</dt>
            <dd><livewire:inquiry-status-switch :inquiry="$inquiry" /></dd>
            <dt class="text-gray-500">Сообщение</dt>
            <dd class="break-words">{{ $inquiry->message ?: '—' }}</dd>
            <dt class="text-gray-500">Создано</dt>
            <dd class="break-words">{{ $inquiry->created_at?->format('d.m.Y H:i') }}</dd>
            @if ($inquiry->landing_path)
                <dt class="text-gray-500">Посадочная</dt>
                <dd class="break-words">{{ $inquiry->landing_path }}</dd>
            @endif
        </dl>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl p-5 mb-4">
        <h2 class="text-lg font-semibold mb-3">Активности</h2>
        @if ($inquiry->activities->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Время</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Тип</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Канал</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Примечание</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($inquiry->activities as $activity)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 border-b border-gray-100">{{ $activity->happened_at?->format('d.m.Y H:i') }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $activity->type }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $activity->channel ?: '—' }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $activity->note ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center text-gray-400 py-8 text-sm">Активностей нет</div>
        @endif
    </div>

    <div class="bg-white border border-gray-200 rounded-xl p-5 mb-4">
        <h2 class="text-lg font-semibold mb-3">История статусов</h2>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Дата</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Было</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Стало</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Кто</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Комментарий</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($inquiry->statusHistory as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 border-b border-gray-100">{{ $row->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-2 border-b border-gray-100">{{ $row->from_status ?: '—' }}</td>
                            <td class="px-3 py-2 border-b border-gray-100">{{ $row->to_status }}</td>
                            <td class="px-3 py-2 border-b border-gray-100">{{ $row->changedBy?->name ?: 'система' }}</td>
                            <td class="px-3 py-2 border-b border-gray-100">{{ $row->note ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl p-5">
        <h2 class="text-lg font-semibold mb-3">Attribution history</h2>
        @if ($inquiry->touchpoints->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Время</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Источник</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Страница</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">UTM source</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($inquiry->touchpoints as $tp)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 border-b border-gray-100">{{ $tp->touched_at?->format('d.m.Y H:i') }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $tp->source_kind }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $tp->path ?: '—' }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $tp->utm_source ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center text-gray-400 py-8 text-sm">История касаний отсутствует</div>
        @endif
    </div>
@endsection