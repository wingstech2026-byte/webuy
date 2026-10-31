<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records every change an admin makes (approvals, refunds, settings, moderation...): who, which action,
 * which record, which fields and from where. Field values are not stored, so no secrets end up here.
 */
class AuditAdminActions
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') || ! $request->user()) {
            return $response;
        }

        try {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => $request->route()?->getName() ?? $request->path(),
                'subject' => collect($request->route()?->parameters() ?? [])
                    ->map(fn ($value, $key) => $key.':'.($value instanceof Model ? $value->getRouteKey() : $value))->implode(', ') ?: null,
                'details' => [
                    'fields' => array_values(array_diff(array_keys($request->except(['_token', '_method'])), ['password', 'token'])),
                    'status' => $response->getStatusCode(),
                ],
                'ip' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            report($e); // never block an admin action because logging failed
        }

        return $response;
    }
}
