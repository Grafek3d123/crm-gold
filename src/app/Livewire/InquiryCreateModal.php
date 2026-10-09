<?php

namespace App\Livewire;

use App\Actions\Inquiries\CreateManualInquiryAction;
use App\Enums\InquiryStatus;
use App\Support\InquirySourceKind;
use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;

class InquiryCreateModal extends Component
{
    public bool $show = false;
    public ?string $name = null;
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $message = null;
    public ?string $comment = null;
    public string $sourceKind = 'call';

    protected function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'message' => 'nullable|string|max:5000',
            'comment' => 'nullable|string|max:1000',
            'sourceKind' => 'required|in:call,email,other',
        ];
    }

    protected function messages(): array
    {
        return [
            'phone.max' => 'Телефон слишком длинный',
            'email.email' => 'Некорректный email',
            'sourceKind.in' => 'Неизвестный тип источника',
        ];
    }

    #[On('open-inquiry-create-modal')]
    public function openModal(): void
    {
        Gate::authorize('create', \App\Models\Inquiry::class);
        $this->resetForm();
        $this->show = true;
    }

    public function closeModal(): void
    {
        $this->show = false;
        $this->resetForm();
        $this->resetErrorBag();
    }

    public function resetForm(): void
    {
        $this->name = null;
        $this->phone = null;
        $this->email = null;
        $this->message = null;
        $this->comment = null;
        $this->sourceKind = 'call';
    }

    public function save(CreateManualInquiryAction $action): void
    {
        $this->validate();

        if ($this->phone === null && $this->email === null) {
            $this->addError('phone', 'Нужен телефон или email');
            $this->addError('email', 'Нужен телефон или email');
            return;
        }

        $sourceKind = InquirySourceKind::from($this->sourceKind);
        $data = [
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'message' => $this->message,
            'comment' => $this->comment,
        ];

        try {
            $inquiry = $action->execute($data, $sourceKind);
            $this->closeModal();
            $this->dispatch('notify', 'Обращение зарегистрировано');
            $this->redirect(route('inquiries.show', $inquiry), navigate: true);
        } catch (\RuntimeException $e) {
            $this->addError('sourceKind', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.inquiry-create-modal');
    }
}