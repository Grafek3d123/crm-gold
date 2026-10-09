<?php

namespace App\Http\Controllers;

use App\Actions\Customers\CustomerMergeConflictException;
use App\Actions\Customers\MergeCustomersAction;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CustomerMergeController extends Controller
{
    public function store(Request $request, Customer $winner, MergeCustomersAction $action): RedirectResponse
    {
        Gate::authorize('merge', $winner);

        $data = $request->validate([
            'loser_id' => ['required', 'integer', 'different:'.$winner->id],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $loser = Customer::query()->findOrFail($data['loser_id']);

        if ($winner->is($loser)) {
            return back()->withErrors(['loser_id' => 'Нельзя слить карточку с самой собой']);
        }

        if (! Gate::allows('mergeInto', [$winner, $loser])) {
            return back()->withErrors(['loser_id' => 'Эти карточки нельзя объединить']);
        }

        try {
            $action->execute($winner, $loser, $data['reason']);
        } catch (CustomerMergeConflictException $e) {
            return back()->withErrors(['loser_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('customers.show', $winner)
            ->with('status', 'Карточки объединены');
    }
}
