<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomersController extends Controller
{
    public function index(Request $request, UserAccessService $access): View
    {
        return view('staff.customers', [
            'user' => $request->user(),
            'showFinancials' => $access->allows($request->user(), 'customers.view_spending'),
        ]);
    }
}
