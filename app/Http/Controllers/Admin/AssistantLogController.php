<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssistantLog;
use App\Services\Assistant\ShoppingAssistant;

/** AI shopping assistant usage: requests, tokens and errors. */
class AssistantLogController extends Controller
{
    public function index()
    {
        return view('admin.assistant.index', [
            'enabled' => ShoppingAssistant::enabled(),
            'logs' => AssistantLog::with('user')->latest('id')->paginate(40),
            'today' => AssistantLog::where('created_at', '>=', today())->count(),
            'tokens' => [
                'input' => (int) AssistantLog::where('created_at', '>=', now()->startOfMonth())->sum('input_tokens'),
                'output' => (int) AssistantLog::where('created_at', '>=', now()->startOfMonth())->sum('output_tokens'),
            ],
        ]);
    }
}
