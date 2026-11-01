<?php

namespace Tests\Feature;

use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** English or Krio on the website, chosen in the footer and remembered in a cookie. */
class KrioLanguageTest extends TestCase
{
    use Marketplace;

    public function test_a_visitor_switches_to_krio_and_back(): void
    {
        $product = $this->makeProduct();

        $this->get('/')->assertSee('Shop by category')->assertSee('Krio');

        $this->from('/')->post(route('language'), ['language' => 'kri'])->assertRedirect('/')->assertCookie('language', 'kri');

        $this->withCookie('language', 'kri')->get('/')
            ->assertSee('Luk di kain dem')->assertSee('Bai en sel izi fo Salone.')->assertSee('Fen res, fon, sola panel…')
            ->assertDontSee('Shop by category');
        $this->withCookie('language', 'kri')->get(route('products.show', $product))->assertSee('Put am na baskit')->assertSee('Bai am naw');

        $this->post(route('language'), ['language' => 'fr'])->assertSessionHasErrors('language');
        $this->withCookie('language', 'fr')->get('/')->assertSee('Shop by category'); // unknown: English
    }

    public function test_every_krio_phrase_has_an_english_original_in_use(): void
    {
        $krio = json_decode(file_get_contents(lang_path('kri.json')), true);

        $this->assertNotEmpty($krio);
        $this->assertSame(array_keys($krio), array_unique(array_keys($krio)));
        $this->assertNotContains('', $krio, 'A Krio phrase is empty.');
    }
}
