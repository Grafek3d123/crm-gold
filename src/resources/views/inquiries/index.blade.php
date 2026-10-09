@extends('layouts.app')

@section('title', 'Обращения — CRM')

@section('content')
    <livewire:inquiry-create-modal />

    <div class="flex justify-between items-center mb-4">
        <h1 class="text-xl font-semibold">Обращения</h1>
        <button wire:click="$dispatch('open-inquiry-create-modal')" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">Зарегистрировать обращение</button>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl p-5">
        @if ($inquiries->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">ID</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Клиент</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Телефон</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Сайт</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Источник</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Статус</th>
                            <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Создано</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($inquiries as $inquiry)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 border-b border-gray-100"><a href="{{ route('inquiries.show', $inquiry) }}" class="text-blue-600 hover:underline">#{{ $inquiry->id }}</a></td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $inquiry->customer?->name ?: '—' }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $inquiry->phone_raw ?: '—' }}</td>
                                <td class="px-3 py-2 border-b border-gray-100">{{ $inquiry->site?->domain ?: '—' }}</td>
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

            <div class="flex gap-1 mt-4">
                {{ $inquiries->links() }}
            </div>
        @else
            <div class="text-center text-gray-400 py-8 text-sm">Обращений пока нет</div>
        @endif
    </div>
@endsection