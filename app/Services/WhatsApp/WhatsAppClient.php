<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The WhatsApp Business Cloud API (Meta): send a text reply, download a photo a shopper sent. */
class WhatsAppClient
{
    public function sendText(string $to, string $body): void
    {
        $response = Http::withToken((string) config('services.whatsapp.token'))->timeout(20)
            ->post(config('services.whatsapp.graph_url').'/'.config('services.whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => mb_substr($body, 0, 4000), 'preview_url' => true],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp did not accept the reply ('.$response->status().').');
        }
    }

    /**
     * Download a photo by its media id into a temporary file. The caller deletes the file.
     *
     * @throws RuntimeException
     */
    public function downloadMedia(string $mediaId): string
    {
        $token = (string) config('services.whatsapp.token');
        $info = Http::withToken($token)->timeout(20)->get(config('services.whatsapp.graph_url').'/'.rawurlencode($mediaId));
        $url = $info->json('url');

        if ($info->failed() || ! is_string($url)) {
            throw new RuntimeException('The photo could not be found on WhatsApp.');
        }

        // At most 8 MB, like photos uploaded on the website.
        if ((int) $info->json('file_size', 0) > 8 * 1024 * 1024) {
            throw new RuntimeException('The photo is too big.');
        }

        $file = Http::withToken($token)->timeout(30)->get($url);

        if ($file->failed()) {
            throw new RuntimeException('The photo could not be downloaded.');
        }

        $path = tempnam(sys_get_temp_dir(), 'wibuy-wa');
        file_put_contents($path, $file->body());

        return $path;
    }
}
