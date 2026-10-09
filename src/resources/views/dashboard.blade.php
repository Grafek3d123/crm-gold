@extends('layouts.app')

@section('title', 'Дашборд — CRM')

@section('content')
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white border border-gray-200 rounded-xl p-5">
            <div class="text-3xl font-bold text-blue-600">{{ $inquiryCount }}</div>
            <div class="text-sm text-gray-500 mt-1">Обращений всего</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-5">
            <div class="text-3xl font-bold text-blue-600">{{ $newCount }}</div>
            <div class="text-sm text-gray-500 mt-1">Новых обращений</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-5">
            <div class="text-3xl font-bold text-blue-600">{{ $customerCount }}</div>
            <div class="text-sm text-gray-500 mt-1">Клиентов</div>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl p-5">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold">Последние обращения</h2>
            <a href="{{ route('inquiries.create') }}" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">Зарегистрировать обращение</a>
        </div>

        @if ($recentInquiries->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">ID</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Клиент</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Телефон</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Источник</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Статус</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Создано</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentInquiries as $inquiry)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 border-b border-gray-100"><a href="{{ route('inquiries.show', $inquiry) }}" class="text-blue-600 hover:underline">#{{ $inquiry->id }}</a></td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $inquiry->customer?->name ?: '—' }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $inquiry->phone_raw ?: '—' }}</td>
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
            <div class="text-center text-gray-400 py-8 text-sm">Обращений пока нет</div>
        @endif
    </div>
@endsection