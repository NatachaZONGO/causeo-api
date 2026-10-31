<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\StatusPost;
use App\Models\User;
use App\Services\Catalog\CatalogService;
use App\Support\StatusLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Liens courts des statuts WhatsApp (getcauseo.com/c/neeb12) : référence par business, redirection
 * vers wa.me avec un message pré-rempli, compteur de clics, sans aucune donnée du visiteur.
 */
class StatusLinkTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        config(['services.status_links.url' => 'https://getcauseo.com']);
        $this->createConversationSchema();
        Storage::fake('supabase_media');

        $this->owner = User::forceCreate(['name' => 'Neeb', 'email' => 'neeb@example.test', 'password' => 'x']);
        $this->business = $this->business('NEEB-RATA MARKET', '22670112233');
        $this->robe = app(CatalogService::class)->saveProduct($this->business, ['name' => 'Robe wax Awa', 'base_price' => 12500], null, [
            ['options' => ['taille' => 'M']],
            ['options' => ['taille' => 'L']],
        ]);

        Sanctum::actingAs($this->owner);
    }

    public function test_statuses_are_numbered_per_business_with_a_readable_unique_code(): void
    {
        $sac = app(CatalogService::class)->saveProduct($this->business, ['name' => 'Sac cuir', 'base_price' => 8000], null, [['options' => []]]);

        $created = $this->postJson("/api/v1/businesses/{$this->business->id}/status-posts", ['product_ids' => [$this->robe->id, $sac->id]])
            ->assertCreated()->json('data');

        $this->assertSame([1, 2], array_column($created, 'reference'));
        $this->assertSame(['neeb1', 'neeb2'], array_column($created, 'short_code'));
        $this->assertSame('https://getcauseo.com/c/neeb1', $created[0]['short_url']);
        $this->assertSame(0, $created[0]['clicks']);
        $this->assertSame("Robe wax Awa – 12 500 FCFA\n👉 Commander : getcauseo.com/c/neeb1", $created[0]['caption_suggestion']);
        $this->assertSame('neeb', $this->business->fresh()->short_slug);

        // La numérotation continue, même après expiration ou suppression d'un statut.
        $this->travel(2)->days();
        StatusPost::where('reference', 2)->delete();
        $this->postJson("/api/v1/businesses/{$this->business->id}/status-posts", ['product_ids' => [$sac->id]])
            ->assertCreated()->assertJsonPath('data.0.reference', 3)->assertJsonPath('data.0.short_code', 'neeb3');

        // Un autre business recommence à 1, avec son propre slug ; même premier mot : une lettre de plus.
        $other = $this->business('Neeb Couture', '22675000000');
        $otherPost = StatusPost::create(['business_id' => $other->id, 'caption' => 'Nouveautés', 'posted_at' => now(), 'expires_at' => now()->addDay()]);
        $this->assertSame(1, $otherPost->reference);
        $this->assertSame('neeba1', $otherPost->short_code);

        // L'index renvoie les mêmes champs.
        $index = $this->getJson("/api/v1/businesses/{$this->business->id}/status-posts")->assertOk()->json('data.0');
        $this->assertEquals(['reference' => 3, 'short_code' => 'neeb3', 'short_url' => 'https://getcauseo.com/c/neeb3', 'clicks' => 0],
            array_intersect_key($index, array_flip(['reference', 'short_code', 'short_url', 'clicks'])));
        $this->assertSame("Sac cuir – 8 000 FCFA\n👉 Commander : getcauseo.com/c/neeb3", $index['caption_suggestion']);
    }

    public function test_short_codes_are_unique_in_the_database(): void
    {
        $post = $this->statusPost();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('status_posts')->where('id', '!=', $post->id)->insert([
            'id' => (string) Str::uuid(), 'business_id' => $this->business('Autre', null)->id, 'reference' => 1, 'short_code' => $post->short_code,
            'posted_at' => now(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_short_link_redirects_to_whatsapp_with_the_prefilled_message_and_counts_the_click(): void
    {
        $post = $this->statusPost();

        $response = $this->get('/c/neeb1', ['REMOTE_ADDR' => '203.0.113.7', 'User-Agent' => 'Mozilla/5.0 (Test visiteur)'])->assertStatus(302);

        $this->assertSame(
            'https://wa.me/22670112233?text=Bonjour%2C%20je%20suis%20int%C3%A9ress%C3%A9%28e%29%20par%20Robe%20wax%20Awa%20%28r%C3%A9f.%201%29',
            $response->headers->get('Location'),
        );
        $this->assertSame('Bonjour, je suis intéressé(e) par Robe wax Awa (réf. 1)', StatusLink::message($post->fresh()));
        $this->assertSame(1, $post->fresh()->clicks);

        // Après l'expiration du statut, le lien marche encore ; les majuscules sont acceptées.
        $this->travel(3)->days();
        $this->get('/c/NEEB1')->assertStatus(302);
        $this->assertSame(2, $post->fresh()->clicks);

        // Aucune donnée du visiteur : ni cookie, ni session, ni IP ou user-agent en base.
        $this->assertSame([], $response->headers->getCookies());
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table'") as $table) {
            $dump = json_encode(DB::table($table->name)->get());
            $this->assertStringNotContainsString('203.0.113.7', $dump, $table->name);
            $this->assertStringNotContainsString('Test visiteur', $dump, $table->name);
        }
    }

    public function test_a_status_without_article_gets_a_message_about_the_status(): void
    {
        $this->post('/api/v1/businesses/'.$this->business->id.'/status-posts', [
            'image' => UploadedFile::fake()->image('statut.jpg', 600, 600),
            'caption' => 'Nouvel arrivage de pagnes',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.0.reference', 1)
            ->assertJsonPath('data.0.caption_suggestion', "Nouvel arrivage de pagnes\n👉 Commander : getcauseo.com/c/neeb1");

        $location = $this->get('/c/neeb1')->assertStatus(302)->headers->get('Location');

        $this->assertSame('https://wa.me/22670112233?text='.rawurlencode('Bonjour, je vous écris au sujet de votre statut (réf. 1)'), $location);
    }

    public function test_a_price_range_and_an_unknown_price_in_the_caption(): void
    {
        $boubou = app(CatalogService::class)->saveProduct($this->business, ['name' => 'Boubou bazin', 'base_price' => 20000], null, [
            ['options' => ['taille' => 'M'], 'price' => 20000],
            ['options' => ['taille' => 'XL'], 'price' => 25000],
        ]);
        $sansPrix = app(CatalogService::class)->saveProduct($this->business, ['name' => 'Tenue sur mesure', 'base_price' => 0], null, [['options' => []]]);

        $this->assertSame("Boubou bazin – à partir de 20 000 FCFA\n👉 Commander : getcauseo.com/c/neeb1", $this->statusPost($boubou)->caption_suggestion);
        $this->assertSame("Tenue sur mesure\n👉 Commander : getcauseo.com/c/neeb2", $this->statusPost($sansPrix)->caption_suggestion);
    }

    public function test_an_unknown_code_or_a_deleted_article_shows_the_expired_page(): void
    {
        $this->get('/c/neeb99')->assertNotFound()->assertSee('Ce lien a expiré');
        $this->get('/c/inconnu1')->assertNotFound();
        $this->get('/c/12')->assertNotFound(); // Pas un code : route introuvable.

        $post = $this->statusPost();
        $this->robe->delete();
        $this->get('/c/neeb1')->assertNotFound()->assertSee('Ce lien a expiré');
        $this->assertSame(0, $post->fresh()->clicks);

        // Sans numéro WhatsApp connu, le lien ne mène nulle part.
        $other = $this->business('Chez Ali', null);
        StatusPost::create(['business_id' => $other->id, 'caption' => 'Promo', 'posted_at' => now(), 'expires_at' => now()->addDay()]);
        $this->get('/c/chez1')->assertNotFound();
    }

    public function test_existing_whatsapp_numbers_are_fetched_once_from_meta(): void
    {
        config(['services.whatsapp.api_url' => 'https://graph.facebook.com/v21.0/']);
        $missing = $this->business('Chez Fatou', null, 'PNID-FATOU');
        $missing->forceFill(['whatsapp_token' => 'token-fatou'])->save();
        Http::fake(['graph.facebook.com/v21.0/PNID-FATOU*' => Http::response(['display_phone_number' => '+226 76 54 32 10', 'id' => 'PNID-FATOU'])]);

        $this->artisan('causeo:whatsapp-numbers')->expectsOutputToContain('1 numéro(s) enregistré(s)')->assertSuccessful();

        $this->assertSame('22676543210', $missing->fresh()->whatsapp_number);
        $this->assertSame('22670112233', $this->business->fresh()->whatsapp_number);
        Http::assertSentCount(1);
    }

    private function business(string $name, ?string $number, ?string $phoneNumberId = null): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $this->owner->id, 'name' => $name, 'modules' => '["catalog"]',
            'whatsapp_number' => $number, 'whatsapp_phone_number_id' => $phoneNumberId ?? 'PNID-'.Str::random(6), 'whatsapp_verified' => true,
        ]);

        return Business::findOrFail($id);
    }

    private function statusPost(?Product $product = null): StatusPost
    {
        return StatusPost::create([
            'business_id' => $this->business->id, 'product_id' => ($product ?? $this->robe)->id,
            'posted_at' => now(), 'expires_at' => now()->addDay(),
        ])->load('product.variants');
    }
}
