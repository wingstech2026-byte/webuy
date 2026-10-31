<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Models\User;
use App\Services\MilestoneService;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Pre-delivery inspections: assign a WiBuy agent, read the report. Also add or remove agents. */
class InspectionController extends Controller
{
    public function index()
    {
        return view('admin.inspections.index', [
            'inspections' => Inspection::with('order.seller', 'order.buyer', 'agent', 'photos')->latest('id')->paginate(20),
            'agents' => User::where('is_agent', true)->orderBy('name')->get(),
        ]);
    }

    public function assign(Request $request, Inspection $inspection, MilestoneService $milestones): RedirectResponse
    {
        $request->validate(['agent_id' => ['required', 'integer']]);

        try {
            $milestones->assign($inspection, User::findOrFail($request->input('agent_id')));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Agent assigned.');
    }

    public function toggleAgent(Request $request): RedirectResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $user = User::where('phone', PhoneNumber::normalize($request->input('phone')))->first();

        if (! $user) {
            return back()->withErrors(['phone' => 'No WiBuy account with that phone number. They must log in once first.']);
        }

        $user->forceFill(['is_agent' => ! $user->is_agent])->save();

        return back()->with('status', $user->is_agent ? "{$user->name} is now an inspection agent." : "{$user->name} is no longer an agent.");
    }
}
