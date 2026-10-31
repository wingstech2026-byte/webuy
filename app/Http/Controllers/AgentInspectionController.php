<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Models\Inspection;
use App\Services\MilestoneService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** WiBuy agents: the inspection jobs and the pass/fail report with photos (opened from the app). */
class AgentInspectionController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->is_agent, 403);

        return view('agent.index', ['jobs' => Inspection::where('agent_id', $request->user()->id)->with('order.seller', 'order.items')->latest('id')->get()]);
    }

    public function edit(Request $request, Inspection $inspection): View
    {
        abort_unless($request->user()->is_agent && $inspection->agent_id === $request->user()->id, 404);

        return view('agent.report', ['inspection' => $inspection->load('order.seller', 'order.items')]);
    }

    public function update(Request $request, Inspection $inspection, MilestoneService $milestones): RedirectResponse
    {
        abort_unless($request->user()->is_agent, 403);
        $data = $request->validate([
            'passed' => ['required', 'boolean'],
            'report' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['required', 'array', 'min:1', 'max:8'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('wibuy.images.max_upload_kb')],
        ]);

        try {
            $milestones->report($request->user(), $inspection, (bool) $data['passed'], $data['report'], $request->file('photos', []));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('agent.inspections.index')->with('status', 'Report sent. The buyer got an SMS.');
    }
}
