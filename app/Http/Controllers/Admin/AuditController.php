<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __invoke(Request $request): View
    {
        $action = trim((string) $request->query('action', ''));
        $query = AuditLog::query()->with(['actor:id,name,email', 'company:id,name'])->latest('id');
        if ($action !== '') {
            $query->where('action', 'like', '%'.$action.'%');
        }
        return view('admin.audit', [
            'logs' => $query->paginate(20)->withQueryString(),
            'action' => $action,
        ]);
    }
}
