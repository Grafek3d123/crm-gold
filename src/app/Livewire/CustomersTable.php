<?php

namespace App\Livewire;

use App\Models\Customer;
use Livewire\Attributes\On;
use Livewire\WithPagination;
use Livewire\Component;
use Illuminate\Support\Facades\Gate;

class CustomersTable extends Component
{
    use WithPagination;

    public string $search = '';
    public int $perPage = 20;
    public ?int $mergeWinnerId = null;
    public string $mergeLoserId = '';
    public string $mergeReason = '';

    protected $queryString = ['search'];

    public function mount(): void
    {
        Gate::authorize('viewAny', Customer::class);
    }

    public function openMergeModal(int $winnerId): void
    {
        Gate::authorize('merge', Customer::findOrFail($winnerId));
        $this->mergeWinnerId = $winnerId;
        $this->mergeLoserId = '';
        $this->mergeReason = '';
        $this->dispatch('open-modal', 'merge-modal');
    }

    public function closeMergeModal(): void
    {
        $this->mergeWinnerId = null;
        $this->mergeLoserId = '';
        $this->mergeReason = '';
        $this->dispatch('close-modal', 'merge-modal');
    }

    public function merge(): void
    {
        Gate::authorize('merge', Customer::findOrFail($this->mergeWinnerId));

        $this->validate([
            'mergeLoserId' => 'required|integer|different:'.$this->mergeWinnerId,
            'mergeReason' => 'required|string|max:1000',
        ]);

        $winner = Customer::findOrFail($this->mergeWinnerId);
        $loser = Customer::findOrFail($this->mergeLoserId);

        if (! Gate::allows('mergeInto', [$winner, $loser])) {
            $this->addError('mergeLoserId', 'Эти карточки нельзя объединить');
            return;
        }

        try {
            app(\App\Actions\Customers\MergeCustomersAction::class)->execute($winner, $loser, $this->mergeReason);
            $this->closeMergeModal();
            $this->dispatch('notify', 'Карточки объединены');
        } catch (\App\Actions\Customers\CustomerMergeConflictException $e) {
            $this->addError('mergeLoserId', $e->getMessage());
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Customer::query()
            ->where('is_merged', false)
            ->withCount(['inquiries', 'touchpoints']);

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('phone_normalized', 'like', "%{$this->search}%")
                    ->orWhere('email_normalized', 'ilike', "%{$this->search}%");
            });
        }

        $customers = $query->latest('id')->paginate($this->perPage);

        return view('livewire.customers-table', [
            'customers' => $customers,
        ]);
    }
}