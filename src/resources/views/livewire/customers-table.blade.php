<div wire:ignore.self>
    <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
        <h2 style="font-size: 1.15rem;">Клиенты</h2>
    </div>

    <div class="field" style="margin-bottom: 1rem;">
        <label for="search">Поиск</label>
        <input type="text" id="search" wire:model.live.debounce.300ms="search" placeholder="Имя, телефон, email..." class="w-full sm:w-64">
    </div>

    @if ($customers->isNotEmpty())
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Имя</th>
                        <th>Телефон</th>
                        <th>Email</th>
                        <th>Обращений</th>
                        <th>Тачпоинтов</th>
                        <th style="width: 120px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $customer)
                        <tr>
                            <td>{{ $customer->id }}</td>
                            <td>{{ $customer->name ?: '—' }}</td>
                            <td>{{ $customer->phone_raw ?: '—' }} <span class="muted">({{ $customer->phone_normalized ?: '—' }})</span></td>
                            <td>{{ $customer->email_raw ?: '—' }} <span class="muted">({{ $customer->email_normalized ?: '—' }})</span></td>
                            <td>{{ $customer->inquiries_count }}</td>
                            <td>{{ $customer->touchpoints_count }}</td>
                            <td>
                                <a href="{{ route('customers.show', $customer) }}" class="btn btn-secondary btn-sm">Открыть</a>
                                @if (auth()->user()?->isAdmin())
                                    <button wire:click="openMergeModal({{ $customer->id }})" class="btn btn-secondary btn-sm" style="margin-left: .4rem;">Объединить</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pager">
            {{ $customers->links() }}
        </div>
    @else
        <div class="empty">Клиенты не найдены</div>
    @endif

    @if ($mergeWinnerId !== null)
        <div id="merge-modal" class="modal-overlay" wire:ignore>
            <div class="modal">
                <h3>Объединить клиента #{{ $mergeWinnerId }} с дублем</h3>
                <div class="field">
                    <label>ID дубля</label>
                    <input type="number" wire:model="mergeLoserId" placeholder="Например, 42" min="1">
                    @error('mergeLoserId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label>Причина объединения</label>
                    <input type="text" wire:model="mergeReason" placeholder="Один и тот же клиент, дубль из формы..." maxlength="1000" required>
                    @error('mergeReason') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="row" style="margin-top: 1rem; justify-content: flex-end;">
                    <button wire:click="closeMergeModal" class="btn btn-secondary">Отмена</button>
                    <button wire:click="merge" class="btn">Объединить</button>
                </div>
            </div>
        </div>
    @endif
</div>