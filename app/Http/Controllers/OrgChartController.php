<?php

namespace App\Http\Controllers;

use App\Services\ProjectRoleService;
use Illuminate\Http\Request;

class OrgChartController extends Controller
{
    public function index(Request $request)
    {
        if (!ProjectRoleService::canViewOrg($request->user()->role)) {
            abort(403, 'Forbidden');
        }
        return view('org.index');
    }
}
