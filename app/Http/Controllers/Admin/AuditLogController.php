<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/** Who changed what in the admin panel. */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $term = trim((string) $request->query('q'));

        return view('admin.audit', [
            'logs' => AuditLog::with('user')
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('action', 'like', "%{$term}%")->orWhere('subject', 'like', "%{$term}%")))
                ->latest('id')->paginate(50)->withQueryString(),
            'term' => $term,
        ]);
    }
}
