<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Customer::class);

        $query = Customer::query()
            ->where('is_merged', false)
            ->withCount(['inquiries', 'touchpoints']);

        if ($search = $request->string('q')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('phone_normalized', 'like', "%{$search}%")
                    ->orWhere('email_normalized', 'ilike', "%{$search}%");
            });
        }

        $customers = $query->latest('id')->paginate(20);

        return view('customers.index', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    public function show(Customer $customer): View
    {
        Gate::authorize('view', $customer);

        $customer->load(['inquiries.site', 'touchpoints', 'identities']);

        return view('customers.show', ['customer' => $customer]);
    }
}
