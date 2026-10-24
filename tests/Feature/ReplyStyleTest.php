<?php

namespace Tests\Feature;

use App\Jobs\IndexManagerReply;
use App\Models\Business;
use App\Models\ManagerReply;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\AI\ManagerReplyService;
use App\Services\AI\StyleProfileService;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

class ReplyStyleTest extends TestCase
{
    use CreatesConversationSchema;

    private const CONTEXT = 'Pagne wax : 7 500 FCFA, coloris bleu ou rouge. Livraison à Ouagadougou : 1 000 FCFA.';

    private const PROFILE_JSON = [
        'address' => 'tu',
        'greetings' => ['Bonjour ma sœur'],
        'closings' => ['On se voit bientôt'],
        'emoji_usage' => 'parfois',
        'emojis' => ['🙏'],
        'expressions' => ['y a pas de souci'],
        'languages' => ['français'],
        'tone' => 'direct et chaleureux',
        'instructions' => 'Tu tutoies, tu écris court, sans formule de politesse, avec parfois 🙏.',
    ];

    private User $owner;

    private Business $business;

    private string $conversationId;

    /** @var list<string> réponses suivantes de Claude ; ensuite, DEFAULT_REPLY. */
    private array $claudeReplies = [];

    private const DEFAULT_REPLY = 'Oui, 7 500 FCFA en bleu 🙏';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.model' => 'claude-test']);
        $this->travelTo('2026-10-22 10:00:00');
        $this->createConversationSchema();

        // Aucun appel réseau à Gemini : un embedding fixe.
        $this->app->instance(EmbeddingService::class, new class extends EmbeddingService
        {
            public function __construct()
            {
            }

            public function embed(string $text): array
            {
                return [0.1, 0.2, 0.3];
            }
        });

        $this->owner = User::forceCreate(['name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x']);
        $this->business = $this->newBusiness('Joyce Boutique');
        $this->conversationId = $this->newConversation($this->business);

        Http::fake(['api.anthropic.com/*' => fn () => Http::response($this->claudeText(array_shift($this->claudeReplies) ?? self::DEFAULT_REPLY))]);
    }

    // --- Réponses du gérant -------------------------------------------------------------------

    public function test_a_dashboard_reply_is_kept_with_the_customer_question(): void
    {
        Queue::fake();
        $this->message('inbound', 'Vous avez le pagne bleu ?', '09:00:00');
        $this->message('inbound', 'Et le prix ?', '09:00:30');
        $reply = $this->message('outbound', 'Oui ma sœur, 7 500 🙏', '09:01:00', 'human');

        $recorded = app(ManagerReplyService::class)->record($this->business, $reply, ManagerReply::SOURCE_DASHBOARD);

        $this->assertSame("Vous avez le pagne bleu ?\nEt le prix ?", $recorded->customer_question);
        $this->assertSame('Oui ma sœur, 7 500 🙏', $recorded->reply);
        $this->assertSame($this->business->id, $recorded->business_id);
        Queue::assertPushed(IndexManagerReply::class, fn ($job) => $job->managerReplyId === $recorded->id);

        // Une seule fois par message.
        app(ManagerReplyService::class)->record($this->business, $reply, ManagerReply::SOURCE_DASHBOARD);
        $this->assertSame(1, ManagerReply::count());
    }

    public function test_the_question_stops_at_the_previous_reply(): void
    {
        Queue::fake();
        $this->message('inbound', 'Vous livrez ?', '09:00:00');
        $this->message('outbound', 'Oui, à Ouaga.', '09:01:00', 'ai');
        $this->message('inbound', 'Combien ?', '09:02:00');
        $reply = $this->message('outbound', '1 000 la livraison', '09:03:00', 'human');

        $recorded = app(ManagerReplyService::class)->record($this->business, $reply, ManagerReply::SOURCE_DASHBOARD);

        $this->assertSame('Combien ?', $recorded->customer_question);
    }

    public function test_an_echo_from_whatsapp_business_is_kept(): void
    {
        Queue::fake();
        DB::table('businesses')->where('id', $this->business->id)->update(['whatsapp_phone_number_id' => 'PNID-JOYCE', 'whatsapp_verified' => true]);
        $this->message('inbound', 'Bonjour, il reste du wax ?', '09:59:00', null, '22670000001');

        $this->postJson('/api/webhook/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'smb_message_echoes', 'value' => [
                'metadata' => ['phone_number_id' => 'PNID-JOYCE'],
                'message_echoes' => [['from' => '22660000000', 'to' => '22670000001', 'id' => 'wamid.ECHO', 'type' => 'text', 'text' => ['body' => 'Oui il en reste 🙏']]],
            ]]],
        ]]])->assertOk();

        $reply = ManagerReply::sole();
        $this->assertSame(ManagerReply::SOURCE_ECHO, $reply->source);
        $this->assertSame('Oui il en reste 🙏', $reply->reply);
        $this->assertSame('Bonjour, il reste du wax ?', $reply->customer_question);
    }

    public function test_nothing_is_kept_when_the_owner_turns_learning_off(): void
    {
        Queue::fake();
        $this->business->update(['reply_style' => ['learn_from_owner' => false]]);
        $reply = $this->message('outbound', 'Oui ma sœur', '09:01:00', 'human');

        $this->assertNull(app(ManagerReplyService::class)->record($this->business, $reply, ManagerReply::SOURCE_DASHBOARD));
        $this->assertSame(0, ManagerReply::count());
        Queue::assertNothingPushed();
    }

    public function test_backfill_takes_past_owner_replies_but_not_bot_or_appointment_messages(): void
    {
        Queue::fake();
        $this->message('outbound', 'Réponse du gérant', '08:00:00', 'human', status: 'answered_by_human');
        $this->message('outbound', 'Réponse du bot', '08:01:00', 'ai', status: 'sent');
        $this->message('outbound', 'Votre rendez-vous est confirmé', '08:02:00', 'human', status: 'sent');

        $this->assertSame(1, app(ManagerReplyService::class)->backfill($this->business));
        $this->assertSame(['Réponse du gérant'], ManagerReply::pluck('reply')->all());
        $this->assertSame(ManagerReply::SOURCE_HISTORY, ManagerReply::sole()->source);
        $this->assertSame(0, app(ManagerReplyService::class)->backfill($this->business));
    }

    // --- Profil de style ------------------------------------------------------------------------

    public function test_profile_is_due_from_10_replies_then_every_20_and_at_most_daily(): void
    {
        $styles = app(StyleProfileService::class);

        $this->addReplies(9);
        $this->assertFalse($styles->isDue($this->business));

        $this->addReplies(1);
        $this->assertTrue($styles->isDue($this->business));

        $this->business->forceFill(['style_profile' => ['instructions' => 'x'], 'style_profile_updated_at' => now()])->save();
        $this->travel(1)->hours();
        $this->addReplies(25);
        $this->assertFalse($styles->isDue($this->business), 'Pas plus d\'une fois par jour.');

        $this->travel(1)->days();
        $this->assertTrue($styles->isDue($this->business));

        $this->business->forceFill(['style_profile_updated_at' => now()])->save();
        $this->travel(2)->days();
        $this->addReplies(19);
        $this->assertFalse($styles->isDue($this->business), 'Moins de 20 nouvelles réponses.');

        $this->business->update(['reply_style' => ['learn_from_owner' => false]]);
        $this->addReplies(30);
        $this->assertFalse($styles->isDue($this->business->fresh()));
    }

    public function test_refresh_uses_only_this_business_replies_and_stores_the_profile(): void
    {
        $other = $this->newBusiness('Autre Boutique');
        ManagerReply::create(['business_id' => $other->id, 'reply' => 'SECRET DE L\'AUTRE BUSINESS', 'source' => 'dashboard']);
        $this->addReplies(12, 'Oui ma sœur, c\'est dispo');
        $this->claudeReplies = ["Voici :\n".json_encode(self::PROFILE_JSON, JSON_UNESCAPED_UNICODE)];

        $profile = app(StyleProfileService::class)->refresh($this->business);

        Http::assertSent(function (Request $request) {
            $content = $request['messages'][0]['content'];

            return str_contains($content, 'Oui ma sœur')
                && ! str_contains($content, 'SECRET')
                && $request['max_tokens'] === 800;
        });
        $this->assertSame('tu', $profile['address']);
        $this->assertSame(6, $profile['median_words']);
        $this->assertSame(12, $profile['sample_size']);
        $this->business->refresh();
        $this->assertSame(self::PROFILE_JSON['instructions'], $this->business->style_profile['instructions']);
        $this->assertTrue($this->business->style_profile_updated_at->equalTo(now()));
    }

    public function test_no_profile_under_10_replies(): void
    {
        $this->addReplies(9);

        $this->assertNull(app(StyleProfileService::class)->refresh($this->business));
        Http::assertNothingSent();
    }

    public function test_the_tenth_reply_builds_the_profile_through_the_queue(): void
    {
        $this->claudeReplies = [json_encode(self::PROFILE_JSON, JSON_UNESCAPED_UNICODE)];
        $this->addReplies(9);

        $reply = $this->message('outbound', 'Oui ma sœur 🙏', '09:30:00', 'human');
        app(ManagerReplyService::class)->record($this->business, $reply, ManagerReply::SOURCE_DASHBOARD);

        $this->assertSame('tu', $this->business->fresh()->style_profile['address']);
        Http::assertSentCount(1);
    }

    public function test_style_profile_command(): void
    {
        $this->addReplies(5);
        $this->artisan('ai:style-profile', ['business' => $this->business->id, '--force' => true])
            ->expectsOutputToContain('moins de 10 réponses')
            ->assertSuccessful();

        $this->claudeReplies = [json_encode(self::PROFILE_JSON, JSON_UNESCAPED_UNICODE)];
        $this->addReplies(5);
        $this->artisan('ai:style-profile', ['business' => $this->business->id])
            ->expectsOutputToContain('profil mis à jour')
            ->expectsOutputToContain('Tu tutoies')
            ->assertSuccessful();
    }

    // --- Prompt -----------------------------------------------------------------------------------

    public function test_prompt_has_concision_rules_the_learned_style_settings_and_examples(): void
    {
        $this->business->forceFill(['style_profile' => self::PROFILE_JSON + ['median_words' => 9], 'style_profile_updated_at' => now()])->save();
        $this->business->update(['reply_style' => ['address' => 'vous', 'emojis' => 'never', 'signature' => 'Joyce']]);

        $this->service(examples: [(object) ['customer_question' => 'Le wax rouge est dispo ?', 'reply' => 'Oui, passe quand tu veux 🙏', 'similarity' => 0.8]])
            ->answer($this->business->fresh(), 'Vous avez le pagne bleu ?');

        $request = $this->sentRequest();
        [$stable, $dynamic] = [$request['system'][0], $request['system'][1]['text']];

        $this->assertSame(['type' => 'ephemeral'], $stable['cache_control']);
        $this->assertStringContainsString('1 à 3 phrases par défaut', $stable['text']);
        $this->assertStringContainsString('Une seule question à la fois', $stable['text']);
        $this->assertStringContainsString('« N\'hésitez pas »', $stable['text']);
        $this->assertStringContainsString('« En tant qu\'assistant »', $stable['text']);
        $this->assertStringContainsString('Réponds dans la langue du client', $stable['text']);
        $this->assertStringNotContainsString('*gras*', $stable['text']);
        $this->assertStringContainsString('Tu tutoies, tu écris court', $stable['text']);
        $this->assertStringContainsString('environ 9 mots', $stable['text']);
        // Les réglages explicites viennent après le profil et priment.
        $this->assertStringContainsString('priment sur le profil appris', $stable['text']);
        $this->assertStringContainsString("Vouvoie toujours le client, même s'il te tutoie.", $stable['text']);
        $this->assertStringContainsString("N'utilise aucun emoji.", $stable['text']);
        $this->assertStringContainsString('« Joyce »', $stable['text']);
        $this->assertLessThan(strrpos($stable['text'], 'Vouvoie toujours'), strpos($stable['text'], 'Tu tutoies, tu écris court'));

        $this->assertStringContainsString('Exemples de la façon dont le gérant écrit', $dynamic);
        $this->assertStringContainsString('Gérant : Oui, passe quand tu veux 🙏', $dynamic);
        $this->assertStringContainsString('servent au ton, PAS aux faits', $dynamic);

        $this->assertSame(400, $request['max_tokens']);
    }

    public function test_very_short_setting_lowers_max_tokens(): void
    {
        $this->business->update(['reply_style' => ['length' => 'very_short']]);

        $this->service()->answer($this->business->fresh(), 'Vous avez le pagne bleu ?');

        $this->assertSame(250, $this->sentRequest()['max_tokens']);
        $this->assertStringContainsString('une phrase, deux au maximum', $this->sentRequest()['system'][0]['text']);
    }

    public function test_learning_off_ignores_the_profile_and_examples(): void
    {
        $this->business->forceFill(['style_profile' => self::PROFILE_JSON, 'style_profile_updated_at' => now()])->save();
        $this->business->update(['reply_style' => ['learn_from_owner' => false]]);
        ManagerReply::create(['business_id' => $this->business->id, 'customer_question' => 'Wax ?', 'reply' => 'Oui', 'source' => 'dashboard']);

        $this->service()->answer($this->business->fresh(), 'Vous avez le pagne bleu ?');

        $request = $this->sentRequest();
        $this->assertStringNotContainsString('Tu tutoies, tu écris court', $request['system'][0]['text']);
        $this->assertStringNotContainsString('Exemples de la façon dont le gérant écrit', $request['system'][1]['text']);
    }

    public function test_examples_come_only_from_this_business_above_the_threshold(): void
    {
        ManagerReply::create(['business_id' => $this->business->id, 'customer_question' => 'Wax ?', 'reply' => 'Oui', 'source' => 'dashboard']);
        $replies = new class(app(EmbeddingService::class)) extends ManagerReplyService
        {
            public array $calls = [];

            public function similarExamples(Business $business, string $vector, int $limit = 3): Collection
            {
                $this->calls[] = [$business->id, $vector, $limit];

                return collect();
            }
        };
        $this->app->instance(ManagerReplyService::class, $replies);

        $this->service()->answer($this->business->fresh(), 'Vous avez le pagne bleu ?');

        $this->assertSame([[$this->business->id, '[0.1,0.2,0.3]', 3]], $replies->calls);
        $this->assertSame(0.6, ManagerReplyService::EXAMPLE_SIMILARITY_THRESHOLD);
    }

    public function test_legacy_style_keeps_the_previous_prompt_for_comparison(): void
    {
        $this->business->forceFill(['style_profile' => self::PROFILE_JSON, 'style_profile_updated_at' => now()])->save();

        $this->service()->withLegacyStyle()->answer($this->business->fresh(), 'Vous avez le pagne bleu ?');

        $request = $this->sentRequest();
        $this->assertSame(1024, $request['max_tokens']);
        $this->assertStringContainsString('*gras*', $request['system'][0]['text']);
        $this->assertStringContainsString('Réponds TOUJOURS en français', $request['system'][0]['text']);
        $this->assertStringNotContainsString('Tu tutoies, tu écris court', $request['system'][0]['text']);
    }

    public function test_canned_replies_follow_the_owner_address_without_markdown(): void
    {
        $this->business->update(['reply_style' => ['address' => 'tu']]);

        $thanks = $this->service()->answer($this->business->fresh(), 'Merci');
        $hello = $this->service()->answer($this->business->fresh(), 'Bonjour');

        $this->assertContains($thanks['answer'], ['Avec plaisir !', 'De rien 🙏', 'Je t\'en prie !']);
        $this->assertDoesNotMatchRegularExpression('/vous|\*/u', $hello['answer']);
        Http::assertNothingSent();
    }

    // --- Vouvoiement ------------------------------------------------------------------------------

    public function test_the_bot_says_vous_by_default_even_when_the_owner_and_the_customer_say_tu(): void
    {
        // Le gérant tutoie ses clients (profil appris) mais n'a rien choisi.
        $this->business->forceFill(['style_profile' => self::PROFILE_JSON, 'style_profile_updated_at' => now()])->save();
        $business = $this->business->fresh();
        $this->assertSame('vous', $business->tone);

        $this->service()->answer($business, 'Tu as le pagne bleu ?');

        $stable = $this->sentRequest()['system'][0]['text'];
        $this->assertStringContainsString("- Vouvoie toujours le client, même s'il te tutoie ou écrit de façon familière", $stable);
        $this->assertStringContainsString("- Vouvoie toujours le client, même s'il te tutoie.", $stable);
        $this->assertStringNotContainsString('Tutoie toujours', $stable);
        $this->assertStringNotContainsString('tutoie les clients', $stable);
        // La consigne vient après le profil appris, qui dit « Tu tutoies ».
        $this->assertGreaterThan(strpos($stable, 'Tu tutoies, tu écris court'), strrpos($stable, 'Vouvoie toujours'));

        $thanks = $this->service()->answer($business, 'Merci');
        $this->assertContains($thanks['answer'], ['Avec plaisir !', 'De rien 🙏', 'Je vous en prie !']);
    }

    /**
     * Cas de NEEB-RATA MARKET : des consignes du gérant rédigées en « tu » (« Tu es
     * l'assistant… propose-lui… ») passaient pour prioritaires et le bot tutoyait.
     */
    public function test_a_reply_that_says_tu_is_rewritten_with_vous(): void
    {
        // Colonne absente du schéma de test : attribut posé sans enregistrer.
        $business = $this->business->fresh()->setAttribute('ai_instructions', "Tu es l'assistant de la boutique. Propose-lui de réserver l'article.");
        $this->claudeReplies = ['Parfait ! Quelle taille portes-tu ?', 'Parfait ! Quelle taille portez-vous ?'];

        $result = $this->service()->answer($business, 'Je veux une chemise');

        $this->assertSame('Parfait ! Quelle taille portez-vous ?', $result['answer']);
        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('Réécris exactement la même réponse en le vouvoyant', collect($requests[1]['messages'])->last()['content']);

        $system = $this->systemPrompt($requests[0]);
        $this->assertStringContainsString("des consignes rédigées en « tu » s'adressent à toi, pas au client", $system);
        $this->assertStringEndsWith('jamais « tu », « te », « ton » ou « ta ».', $requests[0]['system'][1]['text']);
    }

    public function test_the_first_reply_is_kept_if_the_rewrite_still_says_tu(): void
    {
        $this->claudeReplies = ['Ça te plaît ?', "T'as vu la couleur ?"];

        $this->assertSame('Ça te plaît ?', $this->service()->answer($this->business->fresh(), 'Je veux une chemise')['answer']);
        Http::assertSentCount(2);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('repliesWithoutTu')]
    public function test_replies_with_vous_are_sent_without_rewrite(string $reply): void
    {
        $this->claudeReplies = [$reply];

        $this->assertSame($reply, $this->service()->answer($this->business->fresh(), 'Vous avez la chemise ?')['answer']);
        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function repliesWithoutTu(): array
    {
        return [
            'vouvoiement' => ['Oui, la chemise est à 17 500 F. Quelle taille portez-vous ?'],
            'mots contenant ta, te, tu' => ['Nous sommes à Karpala, la boutique est ouverte : venez tester la tunique en coton.'],
            'citation du client' => ['Vous parlez du modèle « tu vas adorer » ? Il est à 12 000 F.'],
        ];
    }

    public function test_with_tu_chosen_nothing_is_rewritten(): void
    {
        $this->business->update(['reply_style' => ['address' => 'tu']]);
        $this->claudeReplies = ['Quelle taille portes-tu ?'];

        $this->assertSame('Quelle taille portes-tu ?', $this->service()->answer($this->business->fresh(), 'Je veux une chemise')['answer']);
        Http::assertSentCount(1);
    }

    public function test_owner_chooses_the_tone_in_the_business_settings(): void
    {
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/businesses/{$this->business->id}";

        $this->assertSame('vous', $this->business->toArray()['tone']);

        $this->putJson($url, ['tone' => 'tu'])
            ->assertOk()
            ->assertJsonPath('business.tone', 'tu')
            ->assertJsonPath('business.reply_style.address', 'tu');

        $this->service()->answer($this->business->fresh(), 'Vous avez le pagne bleu ?');
        $stable = $this->sentRequest()['system'][0]['text'];
        $this->assertStringContainsString("- Tutoie toujours le client, même s'il te vouvoie.", $stable);
        $this->assertStringNotContainsString('Vouvoie toujours', $stable);

        // « tone » prime sur reply_style.address envoyé dans la même requête ; les autres réglages restent.
        $this->putJson($url, ['tone' => 'vous', 'reply_style' => ['address' => 'tu', 'emojis' => 'never']])
            ->assertOk()
            ->assertJsonPath('business.tone', 'vous')
            ->assertJsonPath('business.reply_style.address', 'vous')
            ->assertJsonPath('business.reply_style.emojis', 'never');

        $this->putJson($url, ['tone' => 'toi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tone' => 'Le ton doit être « vous » ou « tu ».']);
    }

    // --- Réglages (API) ---------------------------------------------------------------------------

    public function test_owner_updates_the_reply_style_partially(): void
    {
        Sanctum::actingAs($this->owner);
        $this->assertSame(
            ['address' => null, 'emojis' => null, 'length' => null, 'signature' => null, 'learn_from_owner' => true],
            $this->business->reply_style,
        );

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['reply_style' => ['address' => 'tu', 'emojis' => 'sometimes', 'signature' => ' Joyce ']])
            ->assertOk()
            ->assertJsonPath('business.reply_style.address', 'tu')
            ->assertJsonPath('business.reply_style.signature', 'Joyce');

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['reply_style' => ['length' => 'very_short', 'learn_from_owner' => false]])
            ->assertOk()
            ->assertJsonPath('business.reply_style', ['address' => 'tu', 'emojis' => 'sometimes', 'length' => 'very_short', 'signature' => 'Joyce', 'learn_from_owner' => false]);

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['reply_style' => ['address' => null]])
            ->assertOk()
            ->assertJsonPath('business.reply_style.address', null)
            ->assertJsonPath('business.reply_style.emojis', 'sometimes');
    }

    public function test_reply_style_values_are_validated(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['reply_style' => [
            'address' => 'toi', 'emojis' => 'always', 'length' => 'long', 'signature' => str_repeat('x', 61), 'learn_from_owner' => 'peut-être',
        ]])->assertUnprocessable()->assertJsonValidationErrors([
            'reply_style.address', 'reply_style.emojis', 'reply_style.length', 'reply_style.signature', 'reply_style.learn_from_owner',
        ]);

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['reply_style' => ['color' => 'red']])
            ->assertUnprocessable()->assertJsonValidationErrors('reply_style');
    }

    // --- Évaluation ---------------------------------------------------------------------------------

    public function test_eval_command_replays_real_questions_before_and_after(): void
    {
        $this->app->instance(AIResponseService::class, new FixedContextAIResponseService(self::CONTEXT));
        $this->claudeReplies = [
            "*Bonjour !* Oui, nous avons le pagne wax bleu à *7 500 FCFA*.\n\nN'hésitez pas si vous avez d'autres questions !",
            'Oui, 7 500 en bleu 🙏',
        ];
        $this->message('inbound', 'ok', '09:00:00');
        $this->message('inbound', 'Vous avez le pagne bleu ?', '09:01:00');

        $this->artisan('ai:style-eval', ['business' => $this->business->id, '--limit' => 5])
            ->expectsOutputToContain('Avant')
            ->expectsOutputToContain('Oui, 7 500 en bleu')
            ->expectsOutputToContain('Longueur médiane : 110 caractères avant, 20 après (1 question(s)).')
            ->assertSuccessful();

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertSame([1024, 400], $requests->map(fn (Request $request) => $request['max_tokens'])->all());
    }

    // --- Outils -------------------------------------------------------------------------------------

    /**
     * @param  list<object>  $examples
     */
    private function service(array $examples = []): AIResponseService
    {
        if ($examples === []) {
            return new FixedContextAIResponseService(self::CONTEXT);
        }

        return new class(self::CONTEXT, collect($examples)) extends FixedContextAIResponseService
        {
            public function __construct(string $context, private readonly Collection $examples)
            {
                parent::__construct($context);
            }

            public function findOwnerExamples(Business $business, string $question, int $limit = 3): Collection
            {
                return $this->examples;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function sentRequest(): array
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        return $recorded->last()[0]->data();
    }

    /**
     * @return array<string, mixed>
     */
    private function claudeText(string $text): array
    {
        return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
    }

    private function addReplies(int $count, string $text = 'Oui, c\'est dispo'): void
    {
        for ($i = 0; $i < $count; $i++) {
            ManagerReply::create(['business_id' => $this->business->id, 'customer_question' => 'Question', 'reply' => "{$text} {$i}", 'source' => 'dashboard']);
        }
    }

    private function message(string $direction, string $content, string $time, ?string $sender = 'customer', ?string $phone = null, string $status = 'answered_by_human'): Message
    {
        $conversationId = $phone === null ? $this->conversationId : DB::table('conversations')->where('customer_phone', $phone)->value('id');

        $message = new Message;
        $message->forceFill([
            'conversation_id' => $conversationId,
            'direction' => $direction,
            'sender_type' => $direction === 'inbound' ? 'customer' : $sender,
            'content' => $content,
            'status' => $direction === 'inbound' ? 'received' : $status,
            'created_at' => "2026-10-22 {$time}",
            'updated_at' => "2026-10-22 {$time}",
        ]);
        $message->timestamps = false;
        $message->save();

        return $message;
    }

    private function newBusiness(string $name): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => $name, 'modules' => '[]']);
        $this->assignPlan($id);

        return Business::findOrFail($id);
    }

    private function newConversation(Business $business): string
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $id, 'business_id' => $business->id, 'customer_phone' => '22670000001', 'customer_name' => 'Awa']);

        return $id;
    }
}
