<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->query('role');
        $term = trim((string) $request->query('q'));

        $users = User::with('seller')
            ->when($role === 'admin', fn ($q) => $q->where('is_admin', true))
            ->when($role === 'seller', fn ($q) => $q->whereHas('seller'))
            ->when($role === 'buyer', fn ($q) => $q->where('is_admin', false)->whereDoesntHave('seller'))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->withCount('orders')
            ->latest('id')->paginate(25)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'role' => $role, 'term' => $term]);
    }
}
