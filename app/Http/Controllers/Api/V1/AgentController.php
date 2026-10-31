<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Services\MilestoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** WiBuy inspection agents: their jobs, and the pass/fail report with photos (multipart). */
class AgentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_agent, 403);

        $jobs = Inspection::where('agent_id', $request->user()->id)->with('order.seller', 'order.items')->latest('id')->limit(50)->get();

        return response()->json(['data' => $jobs->map(fn (Inspection $i) => [
            'id' => $i->id,
            'status' => $i->status,
            'order_number' => $i->order->number,
            'shop' => $i->order->seller->shop_name,
            'pickup_address' => $i->order->seller->pickup_address.', '.$i->order->seller->location,
            'items' => $i->order->items->map(fn ($item) => "{$item->quantity} × {$item->title}")->values(),
            'report' => $i->report,
            'report_url' => route('agent.inspections.edit', $i),
        ])]);
    }

    public function report(Request $request, Inspection $inspection, MilestoneService $milestones): JsonResponse
    {
        abort_unless($request->user()->is_agent, 403);
        $data = $request->validate([
            'passed' => ['required', 'boolean'],
            'report' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['required', 'array', 'min:1', 'max:8'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('wibuy.images.max_upload_kb')],
        ]);

        $inspection = $milestones->report($request->user(), $inspection, (bool) $data['passed'], $data['report'], $request->file('photos', []));

        return response()->json(['status' => $inspection->status]);
    }
}
