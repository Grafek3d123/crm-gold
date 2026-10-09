<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard', [
            'inquiryCount' => \App\Models\Inquiry::query()->count(),
            'customerCount' => \App\Models\Customer::query()->where('is_merged', false)->count(),
            'newCount' => \App\Models\Inquiry::query()->where('status', 'new')->count(),
            'recentInquiries' => \App\Models\Inquiry::query()->with('customer')->latest('id')->limit(10)->get(),
        ]);
    }
}
