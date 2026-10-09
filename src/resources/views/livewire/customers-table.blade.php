<div wire:ignore.self class="w-full">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">Клиенты</h2>
    </div>

    <div class="mb-4">
        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Поиск</label>
        <input type="text" id="search" wire:model.live.debounce.300ms="search" placeholder="Имя, телефон, email..." class="w-full sm:w-64 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    </div>

    @if ($customers->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">ID</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Имя</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Телефон</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Email</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Обращений</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase">Тачпоинтов</th>
                        <th class="text-left px-3 py-2 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase w-32">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $customer)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 border-b border-gray-100">{{ $customer->id }}</td>
                            <td class="px-3 py-2 border-b border-gray-100">{{ $customer->name ?: '—' }}</td>
                            <td class="px-3 py-2 border-b border-gray-100">{{ $customer->phone_raw ?: '—' }} <span class="text-gray-400 text-xs">({{ $customer->phone_normalized ?: '—' }})</span></td>
                            <td class="px-3 py-2 border-b border-gray-100">{{ $customer->email_raw ?: '—' }} <span class="text-gray-400 text-xs">({{ $customer->email_normalized ?: '—' }})</span></td>
                            <td class="px-3 py-2 border-b border-gray-100">{{ $customer->inquiries_count }}</td>
                            <td class="px-3 py-2 border-b border-gray-100">{{ $customer->touchpoints_count }}</td>
                            <td class="px-3 py-2 border-b border-gray-100">
                                <a href="{{ route('customers.show', $customer) }}" class="px-3 py-1.5 bg-gray-100 text-gray-900 text-xs rounded-lg hover:bg-gray-200 inline-block">Открыть</a>
                                @if (auth()->user()?->isAdmin())
                                    <button wire:click="openMergeModal({{ $customer->id }})" class="px-3 py-1.5 bg-gray-100 text-gray-900 text-xs rounded-lg hover:bg-gray-200 inline-block ml-1">Объединить</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex gap-1 mt-4">
            {{ $customers->links() }}
        </div>
    @else
        <div class="text-center text-gray-400 py-8 text-sm">Клиенты не найдены</div>
    @endif

    @if ($mergeWinnerId !== null)
        <div id="merge-modal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" wire:ignore>
            <div class="bg-white rounded-xl p-6 w-full max-w-md shadow-xl max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-semibold mb-4">Объединить клиента #{{ $mergeWinnerId }} с дублем</h3>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">ID дубля</label>
                    <input type="number" wire:model="mergeLoserId" placeholder="Например, 42" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @error('mergeLoserId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Причина объединения</label>
                    <input type="text" wire:model="mergeReason" placeholder="Один и тот же клиент, дубль из формы..." maxlength="1000" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @error('mergeReason') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button wire:click="closeMergeModal" class="px-4 py-2 bg-gray-100 text-gray-900 text-sm rounded-lg hover:bg-gray-200">Отмена</button>
                    <button wire:click="merge" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">Объединить</button>
                </div>
            </div>
        </div>
    @endif
</div>