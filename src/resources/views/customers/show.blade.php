@extends('layouts.app')

@section('title', 'Клиент #'.$customer->id.' — CRM')

@section('content')
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-xl font-semibold">Клиент #{{ $customer->id }}</h1>
        <a href="{{ route('customers.index') }}" class="px-4 py-2 bg-gray-100 text-gray-900 text-sm rounded-lg hover:bg-gray-200">К списку</a>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl p-5 mb-4">
        <h2 class="text-lg font-semibold mb-3">Контакты</h2>
        <dl class="grid grid-cols-[160px_1fr] gap-y-1 gap-x-3 text-sm">
            <dt class="text-gray-500">Имя</dt>
            <dd class="break-words">{{ $customer->name ?: '—' }}</dd>
            <dt class="text-gray-500">Телефон</dt>
            <dd class="break-words">{{ $customer->phone_raw ?: '—' }} <span class="text-gray-400">(норм.: {{ $customer->phone_normalized ?: '—' }})</span></dd>
            <dt class="text-gray-500">Email</dt>
            <dd class="break-words">{{ $customer->email_raw ?: '—' }} <span class="text-gray-400">(норм.: {{ $customer->email_normalized ?: '—' }})</span></dd>
            <dt class="text-gray-500">Комментарий</dt>
            <dd class="break-words">{{ $customer->comment ?: '—' }}</dd>
            <dt class="text-gray-500">Создан</dt>
            <dd class="break-words">{{ $customer->created_at?->format('d.m.Y H:i') }}</dd>
        </dl>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl p-5 mb-4">
        <h2 class="text-lg font-semibold mb-3">Обращения</h2>
        @if ($customer->inquiries->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">ID</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Источник</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Статус</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Создано</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customer->inquiries as $inquiry)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 border-b border-gray-100"><a href="{{ route('inquiries.show', $inquiry) }}" class="text-blue-600 hover:underline">#{{ $inquiry->id }}</a></td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $inquiry->source_kind }}</td>
                                <td class="px-3 py-2 border-b border-gray-100"><span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold
                                    @if($inquiry->status->value === 'new') bg-green-50 text-green-700
                                    @elseif($inquiry->status->value === 'qualified') bg-green-50 text-green-700
                                    @elseif($inquiry->status->value === 'in_progress') bg-yellow-50 text-yellow-700
                                    @elseif($inquiry->status->value === 'rejected') bg-red-50 text-red-700
                                    @elseif($inquiry->status->value === 'duplicate') bg-red-50 text-red-700
                                    @elseif($inquiry->status->value === 'converted') bg-blue-50 text-blue-700
                                    @elseif($inquiry->status->value === 'archived') bg-gray-100 text-gray-500
                                    @endif
                                ">{{ $inquiry->status->label() }}</span></td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $inquiry->created_at?->format('d.m.Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center text-gray-400 py-8 text-sm">Обращений нет</div>
        @endif
    </div>

    @if (auth()->user()?->isAdmin())
        <div class="bg-white border border-gray-200 rounded-xl p-5 mb-4">
            <h2 class="text-lg font-semibold mb-3">Объединить с другой карточкой</h2>
            <form method="POST" action="{{ route('customers.merge', $customer) }}">
                @csrf
                <div class="flex gap-2 flex-wrap">
                    <input type="number" name="loser_id" placeholder="ID дубля" min="1" required class="w-32 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <input type="text" name="reason" placeholder="Причина объединения" required class="flex-1 min-w-[220px] px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <button type="submit" class="px-4 py-2 bg-gray-100 text-gray-900 text-sm rounded-lg hover:bg-gray-200">Объединить</button>
                </div>
            </form>
            <p class="text-gray-400 text-sm mt-2">
                Связи дубля переносятся в эту карточку, дубль помечается как объединённый.
                Доступно только администратору.
            </p>
        </div>
    @endif

    <div class="bg-white border border-gray-200 rounded-xl p-5">
        <h2 class="text-lg font-semibold mb-3">Attribution history</h2>
        @if ($customer->touchpoints->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Время</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Источник</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">UTM source</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Кампания</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customer->touchpoints as $tp)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 border-b border-gray-100">{{ $tp->touched_at?->format('d.m.Y H:i') }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $tp->source_kind }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $tp->utm_source ?: '—' }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $tp->utm_campaign ?: '—' }}</td>
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