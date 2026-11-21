<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardOverviewData;

class Admin_DashboardController extends Controller
{
    public function index(DashboardOverviewData $overview)
    {
        return view('admin.admin_dashboard', $overview->forAdmin());
    }
}
