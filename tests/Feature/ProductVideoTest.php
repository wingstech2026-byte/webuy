<?php

namespace Tests\Feature;

use App\Support\VideoLength;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Sellers add a short phone video to a product; its length is read from the file. */
class ProductVideoTest extends TestCase
{
    use Marketplace;

    /** A minimal MP4: an ftyp box, then a moov box holding an mvhd with the length (version 0 or 1). */
    private function mp4(float $seconds, int $version = 0, bool $moovLast = false): string
    {
        $box = fn (string $type, string $body) => pack('N', strlen($body) + 8).$type.$body;
        $timescale = 1000;
        $mvhd = $version === 1
            ? chr(1)."\0\0\0".pack('J', 0).pack('J', 0).pack('N', $timescale).pack('J', (int) ($seconds * $timescale))
            : "\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', $timescale).pack('N', (int) ($seconds * $timescale));
        $ftyp = $box('ftyp', 'isom'.pack('N', 512).'isomiso2mp41');
        $moov = $box('moov', $box('mvhd', str_pad($mvhd, 100, "\0")));
        $mdat = $box('mdat', str_repeat("\0", 64));

        return $moovLast ? $ftyp.$mdat.$moov : $ftyp.$moov.$mdat;
    }

    private function save($product, array $fields)
    {
        return $this->actingAs($product->seller->user)->put(route('seller.products.update', $product), $fields + [
            'title' => $product->title, 'price' => '100', 'stock' => 5, 'unit' => 'piece', 'min_order_quantity' => 1, 'condition' => 'new', 'status' => 'active',
        ]);
    }

    public function test_the_length_is_read_from_mp4_and_mov_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vid');

        foreach ([[12.5, 0, false], [45, 1, false], [30, 0, true]] as [$seconds, $version, $moovLast]) {
            file_put_contents($path, $this->mp4($seconds, $version, $moovLast));
            $this->assertEqualsWithDelta($seconds, VideoLength::seconds($path), 0.01);
        }

        file_put_contents($path, 'not a video at all');
        $this->assertNull(VideoLength::seconds($path));
        unlink($path);
    }

    public function test_a_seller_adds_a_short_video_and_buyers_see_it(): void
    {
        Storage::fake('public');
        $product = $this->makeProduct();

        $this->save($product, ['video' => UploadedFile::fake()->createWithContent('long.mp4', $this->mp4(75))])
            ->assertSessionHasErrors(['general' => 'The video is 75 seconds long. Please keep it to 60 seconds or less.']);
        $this->save($product, ['video' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])->assertSessionHasErrors('video');
        $this->assertNull($product->fresh()->video_path);

        $this->save($product, ['video' => UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4(20))])->assertSessionHasNoErrors();
        $product->refresh();
        Storage::disk('public')->assertExists($product->video_path);
        $this->assertSame(20, $product->video_seconds);

        $this->get(route('products.show', $product))->assertSee('preload="none"', false)->assertSee($product->video_url)->assertSee('Video from the seller');
        $this->getJson("/api/v1/products/{$product->slug}")->assertJsonPath('data.video_url', $product->video_url)->assertJsonPath('data.video_seconds', 20);

        // Replacing deletes the old file; removing clears it.
        $old = $product->video_path;
        $this->save($product, ['video' => UploadedFile::fake()->createWithContent('new.mp4', $this->mp4(10))]);
        Storage::disk('public')->assertMissing($old);
        $this->save($product->fresh(), ['remove_video' => '1']);
        $this->assertNull($product->fresh()->video_path);
    }

    public function test_the_app_uploads_a_video_with_the_product(): void
    {
        Storage::fake('public');
        $product = $this->makeProduct();
        Sanctum::actingAs($product->seller->user);

        $this->post(route('api.v1.seller.products.update', $product), [
            'title' => $product->title, 'price' => '100', 'stock' => 5, 'unit' => 'piece', 'min_order_quantity' => 1, 'condition' => 'new', 'status' => 'active',
            'genuine_guarantee' => '1',
            'video' => UploadedFile::fake()->createWithContent('clip.mov', $this->mp4(15)),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.video_seconds', 15)->assertJsonPath('data.genuine_guarantee', true);
    }
}
