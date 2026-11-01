<?php

namespace App\Services\WhatsApp;

use App\Exceptions\MarketplaceException;
use App\Models\Product;
use App\Models\User;
use App\Services\Assistant\PhotoSearch;
use App\Services\SearchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Shopping by WhatsApp: a shopper texts what they want ("rice 50kg") or sends a photo of an item, and gets
 * back up to 5 matching products with prices and links to buy on WiBuy. Ordering and paying happen on the
 * website or app (the money rules stay the same). Photo search needs a WiBuy account on the same number,
 * because it has a daily limit.
 */
class WhatsAppShopping
{
    private const GREETINGS = ['hi', 'hello', 'hey', 'help', 'start', 'menu', 'how', 'kushe', 'kusheh', 'adu'];

    public function __construct(private SearchService $search, private PhotoSearch $photos, private WhatsAppClient $whatsapp) {}

    /** @param array{from: string, type: string, text?: string, image_id?: string} $message */
    public function reply(array $message): string
    {
        return match ($message['type']) {
            'text' => $this->forText(trim((string) ($message['text'] ?? ''))),
            'image' => $this->forPhoto($message['from'], (string) ($message['image_id'] ?? '')),
            default => $this->help(),
        };
    }

    private function forText(string $text): string
    {
        $words = mb_strtolower(trim($text, " \t\n\r\0\x0B!?.,"));

        if ($words === '' || in_array($words, self::GREETINGS, true) || mb_strlen($words) < 2) {
            return $this->help();
        }

        $products = collect($this->search->search(['q' => mb_substr($text, 0, 100)], 5)->items());

        return $products->isEmpty()
            ? 'Sorry, nobody sells "'.mb_substr($text, 0, 60)."\" on WiBuy right now. Try other words, or send a photo of the item.\n\nAsk sellers for it: ".route('home')
            : 'Here is what we found for "'.mb_substr($text, 0, 60)."\":\n\n".$this->list($products);
    }

    private function forPhoto(string $from, string $mediaId): string
    {
        if (! PhotoSearch::enabled()) {
            return 'Search by photo is not switched on yet. Please type what you are looking for, like "rice 50kg".';
        }

        $user = User::where('phone', $from)->first();

        if (! $user) {
            return 'To search with a photo, first make a free WiBuy account with this phone number: '.route('login')."\n\nOr type what you are looking for, like \"rice 50kg\".";
        }

        $path = null;

        try {
            $path = $this->whatsapp->downloadMedia($mediaId);
            $result = $this->photos->search($user, new UploadedFile($path, 'whatsapp.jpg', null, null, true));
        } catch (RuntimeException) {
            return 'Sorry, we could not open that photo. Please send it again, or type what you are looking for.';
        } catch (MarketplaceException $e) {
            return $e->getMessage();
        } finally {
            if ($path) {
                @unlink($path);
            }
        }

        $understood = $result['understood'];

        if (! $understood['is_item']) {
            return 'We could not see an item to buy in that photo. Try a closer photo of just the item.';
        }

        return $result['products']->isEmpty()
            ? trim($understood['reply'])."\nNobody sells \"{$understood['name']}\" on WiBuy right now. Try typing: ".implode(', ', $understood['search_words'])
            : trim($understood['reply'])."\n\n".$this->list($result['products']->take(5));
    }

    /** @param Collection<int, Product> $products */
    private function list(Collection $products): string
    {
        return $products->values()->map(fn (Product $p, int $i) => ($i + 1).'. '.$p->title.' - '.money($p->price).($p->unit ? " per {$p->unit}" : '')
            ."\n   ".$p->seller->shop_name."\n   ".route('products.show', $p))->implode("\n\n")
            ."\n\nTap a link to buy. Pay with Orange Money or Afrimoney; the seller is paid only after you get your order.";
    }

    private function help(): string
    {
        return "Welcome to WiBuy! Tell us what you want to buy, like \"rice 50kg\" or \"solar panel\", or send a photo of the item. We will send you who sells it and the price.\n\nShop here: ".route('home');
    }
}
