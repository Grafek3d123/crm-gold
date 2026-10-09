<div wire:ignore.self>
    <div class="modal-overlay" style="{{ $show ? '' : 'display: none;' }}">
        <div class="modal">
            <h3>Новое обращение вручную</h3>

            <form wire:submit.prevent="save">
                <div class="field">
                    <label>Тип источника</label>
                    <select wire:model="sourceKind">
                        <option value="call">Входящий звонок</option>
                        <option value="email">Email</option>
                        <option value="other">Другое</option>
                    </select>
                    @error('sourceKind') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label>Имя</label>
                    <input type="text" wire:model="name" placeholder="Иван Петров">
                    @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label>Телефон</label>
                    <input type="text" wire:model="phone" placeholder="+7 (999) 123-45-67">
                    @error('phone') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label>Email</label>
                    <input type="email" wire:model="email" placeholder="client@example.com">
                    @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label>Текст обращения</label>
                    <textarea wire:model="message" placeholder="Суть обращения..."></textarea>
                    @error('message') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label>Комментарий менеджера</label>
                    <textarea wire:model="comment" placeholder="Внутренний комментарий (не виден клиенту)"></textarea>
                    @error('comment') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div class="row" style="margin-top: 1rem; justify-content: flex-end;">
                    <button type="button" wire:click="closeModal" class="btn btn-secondary">Отмена</button>
                    <button type="submit" class="btn">Создать обращение</button>
                </div>
            </form>
        </div>
    </div>
</div>