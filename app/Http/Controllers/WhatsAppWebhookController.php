<?php

namespace App\Http\Controllers;

use App\Jobs\HandleWhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Meta's WhatsApp webhook. GET: Meta checks the verify token once when the webhook is set up.
 * POST: incoming messages. Every POST must carry Meta's signature (HMAC-SHA256 of the body with the app
 * secret), so nobody else can make WiBuy send messages. Meta may send the same message twice; each message
 * id is handled once.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        abort_unless(config('services.whatsapp.enabled'), 404);

        $token = (string) config('services.whatsapp.verify_token');
        $ok = $request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token'));

        return $ok ? response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']) : response('Forbidden', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        abort_unless(config('services.whatsapp.enabled'), 404);

        $secret = (string) config('services.whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');

        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response()->json(['error' => 'Bad signature.'], 403);
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['messages'] ?? []) as $message) {
                    $this->queue($message);
                }
            }
        }

        // Always 200 for a signed call, so Meta does not keep resending it.
        return response()->json(['received' => true]);
    }

    private function queue(array $message): void
    {
        $id = (string) ($message['id'] ?? '');
        $from = preg_replace('/\D/', '', (string) ($message['from'] ?? ''));

        if ($id === '' || $from === '' || ! Cache::add('whatsapp:message:'.sha1($id), true, now()->addDay())) {
            return; // no id, or already handled
        }

        HandleWhatsAppMessage::dispatch(array_filter([
            'from' => $from,
            'type' => (string) ($message['type'] ?? 'unknown'),
            'text' => isset($message['text']['body']) ? mb_substr((string) $message['text']['body'], 0, 300) : null,
            'image_id' => $message['image']['id'] ?? null,
        ], fn ($v) => $v !== null));
    }
}
