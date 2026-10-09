<div class="status-switch">
    <span class="badge {{ $inquiry->status->value }}">{{ $inquiry->status->label() }}</span>

    @if ($transitions->isNotEmpty())
        <form wire:submit.prevent="changeStatus" style="display: inline-flex; gap: .5rem; align-items: center; margin-left: .8rem;">
            <select wire:model="targetStatus" style="min-width: 140px;">
                @foreach ($transitions as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            @error('targetStatus') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror

            <input type="text" wire:model="note" placeholder="Примечание (опционально)" style="width: 180px;">
            @error('note') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror

            <button type="submit" class="btn btn-sm">Сменить</button>
        </form>
    @else
        <span class="muted">Финальный статус</span>
    @endif
</div>