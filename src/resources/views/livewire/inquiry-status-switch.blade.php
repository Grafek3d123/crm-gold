<div class="inline-flex items-center gap-2">
    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold
        @if($inquiry->status->value === 'new') bg-green-50 text-green-700
        @elseif($inquiry->status->value === 'qualified') bg-green-50 text-green-700
        @elseif($inquiry->status->value === 'in_progress') bg-yellow-50 text-yellow-700
        @elseif($inquiry->status->value === 'rejected') bg-red-50 text-red-700
        @elseif($inquiry->status->value === 'duplicate') bg-red-50 text-red-700
        @elseif($inquiry->status->value === 'converted') bg-blue-50 text-blue-700
        @elseif($inquiry->status->value === 'archived') bg-gray-100 text-gray-500
        @endif
    ">
        {{ $inquiry->status->label() }}
    </span>

    @if ($transitions->isNotEmpty())
        <form wire:submit.prevent="changeStatus" class="inline-flex items-center gap-2 ml-2">
            <select wire:model="targetStatus" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 min-w-[140px]">
                @foreach ($transitions as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            @error('targetStatus') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror

            <input type="text" wire:model="note" placeholder="Примечание (опционально)" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm w-48 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            @error('note') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror

            <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700">Сменить</button>
        </form>
    @else
        <span class="text-gray-400 text-sm ml-2">Финальный статус</span>
    @endif
</div>