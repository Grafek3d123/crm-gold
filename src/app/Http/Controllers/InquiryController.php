<?php

namespace App\Http\Controllers;

use App\Actions\Inquiries\CreateManualInquiryAction;
use App\Domain\Inquiries\InquiryStateMachine;
use App\Enums\InquiryStatus;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Support\InquirySourceKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InquiryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Inquiry::class);

        $inquiries = Inquiry::query()
            ->with(['customer', 'site'])
            ->latest('id')
            ->paginate(20);

        return view('inquiries.index', ['inquiries' => $inquiries]);
    }

    public function show(Inquiry $inquiry): View
    {
        Gate::authorize('view', $inquiry);

        $inquiry->load(['customer', 'site', 'statusHistory.changedBy', 'touchpoints', 'activities']);

        return view('inquiries.show', [
            'inquiry' => $inquiry,
            'transitions' => $inquiry->status->transitions(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Inquiry::class);

        return view('inquiries.create');
    }

    public function store(Request $request, CreateManualInquiryAction $action): RedirectResponse
    {
        Gate::authorize('create', Inquiry::class);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'source_kind' => ['required', 'in:call,email,other'],
        ]);

        $sourceKind = InquirySourceKind::from($data['source_kind']);
        $inquiry = $action->execute($data, $sourceKind);

        return redirect()
            ->route('inquiries.show', $inquiry)
            ->with('status', 'Обращение зарегистрировано');
    }

    public function changeStatus(Request $request, Inquiry $inquiry, InquiryStateMachine $machine): RedirectResponse
    {
        Gate::authorize('changeStatus', $inquiry);

        $data = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $to = InquiryStatus::tryFrom($data['status']);

        if ($to === null) {
            return back()->withErrors(['status' => 'Неизвестный статус']);
        }

        try {
            $machine->ensureTransition($inquiry, $to, $request->user()?->id, $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Статус обновлён');
    }
}
