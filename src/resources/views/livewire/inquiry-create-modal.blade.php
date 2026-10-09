<div wire:ignore.self>
    <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" style="{{ $show ? '' : 'display: none;' }}">
        <div class="bg-white rounded-xl p-6 w-full max-w-md shadow-xl max-h-[90vh] overflow-y-auto">
            <h3 class="text-lg font-semibold mb-4">Новое обращение вручную</h3>

            <form wire:submit.prevent="save" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Тип источника</label>
                    <select wire:model="sourceKind" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="call">Входящий звонок</option>
                        <option value="email">Email</option>
                        <option value="other">Другое</option>
                    </select>
                    @error('sourceKind') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Имя</label>
                    <input type="text" wire:model="name" placeholder="Иван Петров" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Телефон</label>
                    <input type="text" wire:model="phone" placeholder="+7 (999) 123-45-67" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @error('phone') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" wire:model="email" placeholder="client@example.com" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Текст обращения</label>
                    <textarea wire:model="message" placeholder="Суть обращения..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 min-h-[90px] resize-y"></textarea>
                    @error('message') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Комментарий менеджера</label>
                    <textarea wire:model="comment" placeholder="Внутренний комментарий (не виден клиенту)" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 min-h-[90px] resize-y"></textarea>
                    @error('comment') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeModal" class="px-4 py-2 bg-gray-100 text-gray-900 text-sm rounded-lg hover:bg-gray-200">Отмена</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">Создать обращение</button>
                </div>
            </form>
        </div>
    </div>
</div>