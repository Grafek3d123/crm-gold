<?php

namespace App\Livewire;

use App\Domain\Inquiries\InquiryStateMachine;
use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use Livewire\Component;
use Illuminate\Support\Facades\Gate;

class InquiryStatusSwitch extends Component
{
    public Inquiry $inquiry;
    public string $targetStatus = '';
    public string $note = '';

    public function mount(Inquiry $inquiry): void
    {
        $this->inquiry = $inquiry->load('statusHistory.changedBy');
        $this->targetStatus = $inquiry->status->value;
        Gate::authorize('changeStatus', $this->inquiry);
    }

    public function changeStatus(): void
    {
        $to = InquiryStatus::tryFrom($this->targetStatus);

        if ($to === null) {
            $this->addError('targetStatus', 'Неизвестный статус');
            return;
        }

        if ($to === $this->inquiry->status) {
            $this->dispatch('notify', 'Статус не изменился');
            return;
        }

        try {
            app(InquiryStateMachine::class)->ensureTransition(
                $this->inquiry,
                $to,
                auth()->id(),
                $this->note ?: null
            );

            $this->inquiry->refresh();
            $this->targetStatus = $this->inquiry->status->value;
            $this->note = '';
            $this->dispatch('notify', 'Статус обновлён');
        } catch (\InvalidArgumentException $e) {
            $this->addError('targetStatus', $e->getMessage());
        }
    }

    public function render()
    {
        $transitions = collect($this->inquiry->status->transitions());

        return view('livewire.inquiry-status-switch', [
            'transitions' => $transitions,
        ]);
    }
}