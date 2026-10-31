<?php

namespace Tests\Feature;

use App\Jobs\IndexManagerReply;
use App\Jobs\RefreshStyleProfile;
use App\Models\Business;
use App\Models\BusinessMedia;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Notification;
use App\Models\Product;
use App\Models\StatusPost;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\Catalog\CatalogService;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FakeWhatsAppService;
use Tests\Support\FakeWhatsAppServiceFactory;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

/**
 * Test réel chez Joyce après fbab092 : l'historique ne fait jamais foi pour les prix et les
 * tailles, « je viens récupérer mon habit déposé » va au gérant, une réponse à un statut n'est
 * jamais devinée, et les statuts du jour enregistrés dans Causeo disent à quoi répond le client.
 */
class StatusPostsAndCatalogTruthTest extends TestCase
{
    use CreatesConversationSchema;

    private const CUSTOMER = '22670000001';

    private const ROBE = 'Robe longue noire à paillettes';

    private const HANDOFF = 'Merci pour votre message, nous le transmettons au responsable qui vous répondra rapidement.';

    private User $owner;

    private Business $business;

    private Product $robe;

    private Product $sac;

    /** Réponses successives de Claude ; la dernière se répète. */
    private array $claudeAnswers = ['La robe longue noire à paillettes est à 15 000 FCFA.'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-28 10:00:00');
        config([
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-test',
            'services.reply_pacing.typing_jitter_seconds' => 0,
        ]);

        $this->createConversationSchema();
        Storage::fake('supabase_media');

        $this->owner = User::forceCreate(['name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $this->owner->id, 'name' => 'Joyce Boutique', 'modules' => '["orders","catalog"]',
            'delivery_enabled' => true, 'whatsapp_phone_number_id' => 'PNID-JOYCE', 'whatsapp_verified' => true,
        ]);
        $this->assignPlan($id);
        $this->business = Business::findOrFail($id);

        $catalog = app(CatalogService::class);
        $this->robe = $catalog->saveProduct($this->business, ['name' => self::ROBE, 'base_price' => 15000, 'category' => 'Robes', 'media_ids' => [$this->photo('robe')]], null, [
            ['options' => ['taille' => 'S'], 'stock_quantity' => 2],
            ['options' => ['taille' => 'M'], 'stock_quantity' => 3],
            ['options' => ['taille' => 'L'], 'stock_quantity' => 1],
        ]);
        $this->sac = $catalog->saveProduct($this->business, ['name' => 'Sac à main cuir', 'base_price' => 8000, 'category' => 'Sacs', 'media_ids' => [$this->photo('sac')]], null, [
            ['options' => [], 'stock_quantity' => 4],
        ]);

        FakeWhatsAppService::$sent = [];
        FakeWhatsAppService::$images = [];
        $this->app->instance(WhatsAppServiceFactory::class, new FakeWhatsAppServiceFactory);
        $this->app->instance(AIResponseService::class, new FixedContextAIResponseService('Joyce Boutique, Ouagadougou. Livraison en ville.'));

        Mail::fake();
        Queue::fake([IndexManagerReply::class, RefreshStyleProfile::class]);
        Http::fake(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com')
            ? Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => count($this->claudeAnswers) > 1 ? array_shift($this->claudeAnswers) : $this->claudeAnswers[0]]],
            ])
            : Http::response(['error' => ['message' => 'URL inattendue']], 500));
    }

    // --- 1. L'historique ne fait pas foi --------------------------------------------------------

    public function test_a_wrong_price_and_an_unchosen_size_from_the_history_are_corrected_before_sending(): void
    {
        // Ancienne réponse erronée de l'assistant : 35 000 FCFA et une taille que la cliente n'a jamais choisie.
        $this->history(['Bonsoir, vous avez la robe noire à paillettes ?', 'Oui, la robe longue noire à paillettes est à 35 000 FCFA, taille S.']);
        $this->claudeAnswers = [
            'Parfait : la robe longue noire à paillettes est à 35 000 FCFA, en taille S. Je prépare le récapitulatif.',
            'La robe longue noire à paillettes est à 15 000 FCFA. Quelle taille souhaitez-vous : S, M ou L ?',
        ];

        $this->receive('wamid.1', 'Je veux la robe longue noire à paillettes');

        $this->assertSame(['La robe longue noire à paillettes est à 15 000 FCFA. Quelle taille souhaitez-vous : S, M ou L ?'], FakeWhatsAppService::$sent);
        $requests = $this->claudeRequests();
        $this->assertCount(2, $requests);
        $correction = end($requests[1]['messages'])['content'];
        $this->assertStringContainsString('Ta réponse cite 35 000 FCFA pour « Robe longue noire à paillettes », qui ne correspond à aucun prix du catalogue (15 000 FCFA)', $correction);
        $this->assertStringContainsString('Ta réponse retient la taille S, que le client n\'a jamais écrite', $correction);
        $this->assertStringContainsString('7. Les prix, les tailles, les couleurs et les disponibilités ne viennent JAMAIS de l\'historique de la conversation', $requests[0]['system'][0]['text']);
        $this->assertStringContainsString('8. Une option (taille, couleur…) n\'est retenue que si le CLIENT l\'a écrite lui-même', $requests[0]['system'][0]['text']);
        $this->assertSame(0, Escalation::count());
    }

    public function test_a_price_still_wrong_after_the_retry_goes_to_the_owner(): void
    {
        $this->claudeAnswers = ['La robe longue noire à paillettes est à 35 000 FCFA.'];

        $this->receive('wamid.1', 'La robe longue noire à paillettes est à combien ?');

        $this->assertCount(2, $this->claudeRequests());
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame(1, Escalation::count());
        $this->assertTrue(Escalation::sole()->silent);
    }

    public function test_a_size_given_by_the_customer_and_catalog_prices_pass_the_check(): void
    {
        $this->claudeAnswers = ['La robe longue noire à paillettes en taille M est à 15 000 FCFA, et 30 000 FCFA pour deux. Le total avec la livraison : 16 500 FCFA.'];

        $this->receive('wamid.1', 'La robe longue noire à paillettes en taille M, c\'est combien ?');

        $this->assertCount(1, $this->claudeRequests());
        $this->assertSame(['La robe longue noire à paillettes en taille M est à 15 000 FCFA, et 30 000 FCFA pour deux. Le total avec la livraison : 16 500 FCFA.'], FakeWhatsAppService::$sent);
    }

    public function test_prices_are_checked_only_for_named_catalog_articles(): void
    {
        $catalog = app(CatalogService::class);

        $this->assertSame([], $catalog->priceMismatches($this->business, 'La robe longue noire à paillettes coûte 15.000 F.'));
        $this->assertSame([], $catalog->priceMismatches($this->business, 'Le sac à main en cuir est à 8 000 FCFA, livraison 1 500 FCFA en plus.'));
        $this->assertSame([], $catalog->priceMismatches($this->business, 'Nos ceintures sont à 3 000 FCFA.'));
        $this->assertSame(
            [['product' => 'Sac à main cuir', 'price' => 12000, 'prices' => [8000]]],
            $catalog->priceMismatches($this->business, 'Le sac à main en cuir est à 12000 francs.'),
        );
    }

    // --- 2. Réclamation sur une prestation passée -----------------------------------------------

    public function test_coming_to_collect_a_deposited_outfit_goes_straight_to_the_owner(): void
    {
        $this->receive('wamid.1', 'Je viens récupérer mon habit déposé');

        $this->assertSame([self::HANDOFF], FakeWhatsAppService::$sent);
        $this->assertSame(Escalation::REASON_PAST_ORDER, Escalation::sole()->reason);
        $this->assertSame([], $this->claudeRequests());
    }

    public function test_other_claims_on_a_past_service_are_detected_but_not_new_requests(): void
    {
        $claims = [
            'Bonjour, ma retouche est prête ?',
            'Ça fait depuis des semaines que j\'attends ma tenue',
            'J\'avais déposé deux pagnes pour la couture',
            'Est-ce que c\'est prêt ?',
        ];

        foreach ($claims as $i => $claim) {
            $this->receive("wamid.C{$i}", $claim, '2267100000'.$i);
        }

        $this->assertSame(array_fill(0, 4, self::HANDOFF), FakeWhatsAppService::$sent);
        $this->assertSame([Escalation::REASON_PAST_ORDER], Escalation::pluck('reason')->unique()->values()->all());
        $this->assertSame([], $this->claudeRequests());

        // Une nouvelle demande de service passe par l'assistant.
        $this->claudeAnswers = ['Nous vérifions et revenons vers vous très vite'."\nJE_NE_SAIS_PAS"];
        $this->receive('wamid.N1', 'Vous faites des retouches ?', '22672000001');
        $this->assertCount(1, $this->claudeRequests());
    }

    // --- 3. Réponse à un statut : jamais devinée ------------------------------------------------

    public function test_a_status_reply_is_never_answered_from_the_history_and_the_owner_is_reminded(): void
    {
        $this->history(['La robe longue noire à paillettes est dispo ?', 'Oui, la robe longue noire à paillettes est à 15 000 FCFA.']);

        $this->claudeAnswers = ['JE_NE_SAIS_PAS'];
        $this->replyToStatus('wamid.1', "C'est combien ?");

        // Ni le prix de l'historique ni une capture demandée : le gérant prend le relais, sans message au client.
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertStringContainsString("Ne suppose jamais l'article d'après l'historique de la conversation", json_encode($this->claudeRequests()[0]['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(Escalation::REASON_STATUS_REPLY, Escalation::sole()->reason);
        $hint = Notification::where('type', 'status')->sole();
        $this->assertSame('Awa répond à un de vos statuts', $hint->title);
        $this->assertStringContainsString('Enregistrez vos statuts dans Causeo pour que l\'assistant puisse répondre', $hint->body);
        $this->assertSame('status_hint', $hint->data['kind']);

        // Le rappel part au plus une fois par jour.
        $this->replyToStatus('wamid.3', "C'est combien ?", '22671111111');
        $this->assertSame(1, Notification::where('type', 'status')->count());
    }

    public function test_a_status_reply_that_names_the_article_is_answered_without_the_history(): void
    {
        $this->replyToStatus('wamid.1', 'La robe longue noire à paillettes du statut, elle est à combien ?');

        $userMessage = end($this->claudeRequests()[0]['messages'])['content'];
        $this->assertStringContainsString("Ne suppose jamais l'article d'après l'historique de la conversation", $userMessage);
        $this->assertSame(['La robe longue noire à paillettes est à 15 000 FCFA.'], FakeWhatsAppService::$sent);
    }

    // --- 4. Statuts du jour ---------------------------------------------------------------------

    public function test_with_one_status_of_the_day_the_reply_is_about_its_article(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/businesses/{$this->business->id}/status-posts", ['product_ids' => [$this->robe->id]])
            ->assertCreated()
            ->assertJsonPath('message', 'Votre statut a été enregistré.')
            ->assertJsonPath('data.0.product.name', self::ROBE)
            ->assertJsonPath('data.0.expires_at', '2026-10-29T10:00:00.000000Z');

        $this->replyToStatus('wamid.1', "C'est combien ?");

        $userMessage = end($this->claudeRequests()[0]['messages'])['content'];
        $this->assertStringContainsString("Le client répond au statut WhatsApp du jour de la boutique, qui montre l'article du catalogue « Robe longue noire à paillettes » :", $userMessage);
        $this->assertStringContainsString('"price":15000', $userMessage);
        $this->assertSame(['La robe longue noire à paillettes est à 15 000 FCFA.'], FakeWhatsAppService::$sent);
        $this->assertSame(0, Notification::where('type', 'status')->count());
    }

    public function test_with_one_imported_image_the_assistant_sees_the_status_photo(): void
    {
        Sanctum::actingAs($this->owner);
        $this->post("/api/v1/businesses/{$this->business->id}/status-posts", [
            'image' => UploadedFile::fake()->image('statut.jpg', 600, 600),
            'caption' => 'Nouvel arrivage de robes',
        ], ['Accept' => 'application/json'])->assertCreated();

        $media = BusinessMedia::where('file_path', 'like', 'status/%')->sole();
        $this->assertFalse($media->is_active);
        Storage::disk('supabase_media')->assertExists($media->file_path);

        $this->replyToStatus('wamid.1', "C'est combien ?");

        [$image, $text] = end($this->claudeRequests()[0]['messages'])['content'];
        $this->assertSame('image', $image['type']);
        $this->assertStringContainsString("Le client répond au statut WhatsApp du jour de la boutique (légende : « Nouvel arrivage de robes »). L'image du statut est jointe au message", $text['text']);
    }

    public function test_with_several_statuses_the_assistant_sends_their_photos_and_asks_which_one(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/businesses/{$this->business->id}/status-posts", ['product_ids' => [$this->robe->id, $this->sac->id]])
            ->assertCreated()
            ->assertJsonPath('message', 'Vos statuts ont été enregistrés.');

        $this->replyToStatus('wamid.1', "C'est combien ?");

        $this->assertSame([], $this->claudeRequests());
        // Publiés au même moment : l'ordre des deux articles n'importe pas.
        $this->assertCount(1, FakeWhatsAppService::$sent);
        $this->assertContains(FakeWhatsAppService::$sent[0], [
            'Voici les articles de nos statuts du jour : Sac à main cuir et Robe longue noire à paillettes. Lequel vous intéresse ?',
            'Voici les articles de nos statuts du jour : Robe longue noire à paillettes et Sac à main cuir. Lequel vous intéresse ?',
        ]);
        $this->assertEqualsCanonicalizing(['https://cdn.test/robe.jpg', 'https://cdn.test/sac.jpg'], FakeWhatsAppService::$images);
    }

    public function test_at_most_four_status_photos_are_sent(): void
    {
        foreach (range(1, 5) as $i) {
            $product = app(CatalogService::class)->saveProduct($this->business, ['name' => "Pagne wax n°{$i}", 'base_price' => 5000, 'media_ids' => [$this->photo("pagne-{$i}")]], null, [['options' => []]]);
            StatusPost::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'posted_at' => now()->subMinutes($i), 'expires_at' => now()->addHours(23)]);
        }

        $this->replyToStatus('wamid.1', "C'est combien ?");

        $this->assertCount(4, FakeWhatsAppService::$images);
    }

    // --- 4. Article non précisé (Meta n'envoie aucune référence au statut) -------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('unnamedArticleQuestions')]
    public function test_an_unnamed_article_without_status_of_the_day_goes_silently_to_the_owner(string $question): void
    {
        config(['services.anthropic.escalation_summary' => true]);

        $this->receive('wamid.1', $question);

        // Ni prix, ni article, ni photo : rien n'est envoyé et Claude n'est pas appelé.
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame([], FakeWhatsAppService::$images);
        $this->assertSame([], $this->claudeRequests());

        $escalation = Escalation::sole();
        $this->assertTrue($escalation->silent);
        $this->assertSame(Escalation::REASON_STATUS_REPLY, $escalation->reason);
        $this->assertSame("Le client demande « {$question} » sur un article non précisé, probablement un statut.", $escalation->summary);
        $this->assertSame($escalation->summary, Notification::where('type', 'escalation')->sole()->body);
        $this->assertSame(1, Notification::where('type', 'status')->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unnamedArticleQuestions(): array
    {
        return [
            'combien' => ["C'est combien ?"],
            'disponible en XL' => ["C'est disponible en XL ?"],
            'cet haut' => ['Cet haut est à combien ?'],
            'celle-ci' => ['Vous avez celle-ci en rouge ?'],
        ];
    }

    public function test_an_unnamed_article_with_statuses_of_the_day_gets_their_photos_and_one_question(): void
    {
        $extra = app(CatalogService::class)->saveProduct($this->business, ['name' => 'Pagne wax bleu', 'base_price' => 5000, 'media_ids' => [$this->photo('pagne')]], null, [['options' => []]]);
        $boubou = app(CatalogService::class)->saveProduct($this->business, ['name' => 'Boubou bazin', 'base_price' => 25000, 'media_ids' => [$this->photo('boubou')]], null, [['options' => []]]);
        foreach ([$this->robe, $this->sac, $extra, $boubou] as $i => $product) {
            StatusPost::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'posted_at' => now()->subMinutes($i + 1), 'expires_at' => now()->addHours(23)]);
        }

        // Aucune référence au statut dans le webhook (pas de context).
        $this->receive('wamid.1', "C'est disponible en XL ?");

        $this->assertSame([], $this->claudeRequests());
        $this->assertSame(["C'est pour l'un de ces articles ?"], FakeWhatsAppService::$sent);
        // 3 photos au plus, les statuts les plus récents.
        $this->assertSame(['https://cdn.test/robe.jpg', 'https://cdn.test/sac.jpg', 'https://cdn.test/pagne.jpg'], FakeWhatsAppService::$images);
        $this->assertSame(0, Escalation::count());

        // Toujours pas d'article nommé : le gérant prend le relais, sans rien envoyer.
        $this->travel(1)->minutes();
        $this->receive('wamid.2', 'Vous savez bien');
        $this->assertCount(1, FakeWhatsAppService::$sent);
        $this->assertTrue(Escalation::sole()->silent);
        $this->assertSame([], $this->claudeRequests());
    }

    public function test_with_two_statuses_of_the_day_both_photos_are_sent(): void
    {
        foreach ([$this->robe, $this->sac] as $i => $product) {
            StatusPost::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'posted_at' => now()->subMinutes($i + 1), 'expires_at' => now()->addHours(23)]);
        }

        $this->receive('wamid.1', "C'est combien ?");

        $this->assertSame(["C'est pour l'un de ces articles ?"], FakeWhatsAppService::$sent);
        $this->assertSame(['https://cdn.test/robe.jpg', 'https://cdn.test/sac.jpg'], FakeWhatsAppService::$images);
    }

    public function test_a_named_catalog_article_is_answered_normally_with_its_price(): void
    {
        app(CatalogService::class)->saveProduct($this->business, ['name' => 'Chemise lin cassé', 'base_price' => 9000, 'category' => 'Chemises'], null, [['options' => ['taille' => 'L']]]);
        $this->claudeAnswers = ['La chemise lin cassé est à 9 000 FCFA.'];

        $this->receive('wamid.1', 'La chemise lin cassé c\'est combien ?');

        $this->assertSame(['La chemise lin cassé est à 9 000 FCFA.'], FakeWhatsAppService::$sent);
        $this->assertSame(0, Escalation::count());
        $this->assertStringContainsString("- Si le client parle d'un article sans le nommer (« c'est combien ? », « disponible en XL ? », « cet haut », « celle-ci ») et qu'aucun article précis n'a été identifié dans les derniers messages de la conversation, ne devine JAMAIS de quel article il s'agit : ni prix, ni disponibilité, ni photo, ni nom d'article. Écris seulement JE_NE_SAIS_PAS.", $this->claudeRequests()[0]['system'][0]['text']);
    }

    public function test_a_category_designates_articles_and_is_answered_with_the_catalog(): void
    {
        app(CatalogService::class)->saveProduct($this->business, ['name' => 'Chemise lin cassé', 'base_price' => 9000, 'category' => 'Chemises'], null, [['options' => ['taille' => 'XL']]]);
        $this->claudeAnswers = ['Oui, la chemise lin cassé existe en XL à 9 000 FCFA.'];

        $this->receive('wamid.1', 'Vous avez des chemises en XL ?');

        $this->assertSame(['Oui, la chemise lin cassé existe en XL à 9 000 FCFA.'], FakeWhatsAppService::$sent);
        $this->assertCount(1, $this->claudeRequests());
        $this->assertSame(0, Escalation::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('discoveryRequests')]
    public function test_a_request_to_discover_the_offer_gets_a_normal_presentation(string $question): void
    {
        $robePhoto = $this->robe->media_ids[0];
        $answer = 'Nous avons la robe longue noire à paillettes à 15 000 FCFA et le sac à main cuir à 8 000 FCFA.';
        $this->claudeAnswers = ["{$answer}\nMEDIA:{$robePhoto}"];

        $this->receive('wamid.1', $question);

        $this->assertSame([$answer], FakeWhatsAppService::$sent);
        $this->assertSame(0, Escalation::count());
        $this->assertCount(1, $this->claudeRequests());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function discoveryRequests(): array
    {
        return [
            "qu'est-ce que vous avez" => ["Qu'est-ce que vous avez ?"],
            'quoi comme modèles' => ['Vous avez quoi comme modèles ?'],
            'nouveautés' => ['Montrez-moi vos nouveautés'],
        ];
    }

    public function test_an_article_named_earlier_in_the_conversation_can_be_priced(): void
    {
        $this->history(['Vous avez le sac à main cuir ?', 'Oui, le sac à main cuir est disponible.']);
        $this->claudeAnswers = ['Le sac à main cuir est à 8 000 FCFA.'];

        $this->receive('wamid.1', "C'est combien ?");

        $this->assertSame(['Le sac à main cuir est à 8 000 FCFA.'], FakeWhatsAppService::$sent);
        $this->assertSame(0, Escalation::count());
    }

    public function test_an_invented_article_price_or_photo_is_never_sent(): void
    {
        // Le message ne nomme aucun article du catalogue : Claude en invente un, avec son prix et sa photo.
        $robePhoto = $this->robe->media_ids[0];
        $this->claudeAnswers = ["La robe longue noire à paillettes est disponible en XL à 15 000 FCFA.\nMEDIA:{$robePhoto}"];

        $this->receive('wamid.1', 'Et pour le mariage de samedi ?');

        $this->assertCount(1, $this->claudeRequests());
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame([], FakeWhatsAppService::$images);
        $escalation = Escalation::sole();
        $this->assertTrue($escalation->silent);
        $this->assertSame('Le client demande « Et pour le mariage de samedi ? » sur un article non précisé, probablement un statut.', $escalation->summary);
    }

    public function test_the_owner_lists_and_deletes_statuses_and_only_active_ones_count(): void
    {
        Sanctum::actingAs($this->owner);
        $old = StatusPost::create(['business_id' => $this->business->id, 'product_id' => $this->sac->id, 'posted_at' => now()->subHours(25), 'expires_at' => now()->subHour()]);
        $this->post("/api/v1/businesses/{$this->business->id}/status-posts", ['image' => UploadedFile::fake()->image('a.png'), 'caption' => 'Promo'], ['Accept' => 'application/json'])->assertCreated();

        $list = $this->getJson("/api/v1/businesses/{$this->business->id}/status-posts")->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('Promo', $list[0]['caption']);
        $this->assertNotSame($old->id, $list[0]['id']);

        $media = BusinessMedia::findOrFail($list[0]['media_id']);
        $this->deleteJson("/api/v1/status-posts/{$list[0]['id']}")->assertOk()->assertJsonPath('message', 'Le statut a été supprimé.');
        $this->assertNull(StatusPost::find($list[0]['id']));
        $this->assertNull(BusinessMedia::find($media->id));
        Storage::disk('supabase_media')->assertMissing($media->file_path);
    }

    public function test_status_creation_is_validated_and_private(): void
    {
        Sanctum::actingAs($this->owner);
        $other = User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']);
        $otherId = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $otherId, 'user_id' => $other->id, 'name' => 'Autre', 'modules' => '["catalog"]']);
        $foreign = app(CatalogService::class)->saveProduct(Business::findOrFail($otherId), ['name' => 'Autre robe', 'base_price' => 1000], null, [['options' => []]]);

        $this->postJson("/api/v1/businesses/{$this->business->id}/status-posts", [])
            ->assertStatus(422)->assertJsonValidationErrors(['product_ids' => 'Choisissez au moins un article du catalogue, ou importez une image.']);
        $this->postJson("/api/v1/businesses/{$this->business->id}/status-posts", ['product_ids' => [$foreign->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['product_ids.0' => 'Article introuvable dans votre catalogue.']);
        $this->postJson("/api/v1/businesses/{$this->business->id}/status-posts", ['product_ids' => [$this->robe->id], 'posted_at' => now()->addHour()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors(['posted_at' => 'La date de publication ne peut pas être dans le futur.']);

        $post = StatusPost::create(['business_id' => $this->business->id, 'product_id' => $this->robe->id, 'posted_at' => now(), 'expires_at' => now()->addDay()]);
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/businesses/{$this->business->id}/status-posts")->assertForbidden();
        $this->deleteJson("/api/v1/status-posts/{$post->id}")->assertForbidden();
        $this->assertNotNull(StatusPost::find($post->id));
    }

    // --- Outils -------------------------------------------------------------------------------------

    private function photo(string $name): string
    {
        return BusinessMedia::create([
            'business_id' => $this->business->id, 'type' => 'image', 'title' => $name, 'file_path' => "{$name}.jpg",
            'public_url' => "https://cdn.test/{$name}.jpg", 'mime_type' => 'image/jpeg', 'file_size' => 1000, 'keywords' => [], 'is_active' => true,
        ])->id;
    }

    /**
     * Échanges précédents de la conversation (client, assistant, client…), il y a quelques minutes.
     *
     * @param  list<string>  $texts
     */
    private function history(array $texts): void
    {
        $conversation = Conversation::create(['business_id' => $this->business->id, 'customer_phone' => self::CUSTOMER, 'customer_name' => 'Awa']);

        foreach ($texts as $i => $text) {
            $message = $conversation->messages()->create([
                'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
                'sender_type' => $i % 2 === 0 ? 'customer' : 'ai',
                'content' => $text,
                'status' => $i % 2 === 0 ? 'answered_by_ai' : 'sent',
            ]);
            $message->forceFill(['created_at' => now()->subMinutes(10 - $i), 'updated_at' => now()->subMinutes(10 - $i)])->save();
        }
    }

    private function replyToStatus(string $messageId, string $text, string $from = self::CUSTOMER): void
    {
        $this->receiveRaw(['id' => $messageId, 'type' => 'text', 'text' => ['body' => $text], 'context' => ['from' => '22625000000', 'id' => 'wamid.STATUS-'.$messageId]], $from);
    }

    private function receive(string $messageId, string $text, string $from = self::CUSTOMER): void
    {
        $this->receiveRaw(['id' => $messageId, 'type' => 'text', 'text' => ['body' => $text]], $from);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function receiveRaw(array $message, string $from = self::CUSTOMER): void
    {
        $this->postJson('/api/webhook/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PNID-JOYCE'],
                        'contacts' => [['profile' => ['name' => 'Awa'], 'wa_id' => $from]],
                        'messages' => [['from' => $from] + $message],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claudeRequests(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }
}
