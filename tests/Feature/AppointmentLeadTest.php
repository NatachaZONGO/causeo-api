<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Support\OpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

/**
 * NEEB-RATA MARKET (boutique, module Rendez-vous, formule Pro) n'avait jamais eu de rendez-vous :
 * le bot attendait que le client donne date et heure, et refusait une prise de mesures absente du
 * catalogue. Il mène maintenant la prise de rendez-vous avec des créneaux tirés des horaires.
 */
class AppointmentLeadTest extends TestCase
{
    use CreatesConversationSchema;

    private const WHATSAPP_NAME = 'Awa Ouédraogo';

    private const SLOTS = 'demain samedi 31 octobre à 10h ou lundi 2 novembre à 15h';

    private Business $business;

    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.model' => 'claude-test']);
        // Vendredi 30 octobre 2026, 10h à Ouagadougou.
        $this->travelTo('2026-10-30 10:00:00');
        $this->createConversationSchema();
        Schema::table('businesses', fn (Blueprint $table) => $table->json('opening_hours')->nullable());

        $owner = User::forceCreate(['name' => 'Gérante', 'email' => 'neeb@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $owner->id, 'name' => 'NEEB-RATA MARKET', 'type' => 'boutique',
            'activity_profile' => 'boutique', 'modules' => '["appointments","catalog"]',
            'opening_hours' => json_encode(['lundi-samedi' => '9h - 19h', 'dimanche' => 'fermé']),
        ]);
        $this->assignPlan($id);
        $this->business = Business::findOrFail($id);

        $this->conversationId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $this->conversationId, 'business_id' => $id, 'customer_phone' => '22670123456', 'customer_name' => self::WHATSAPP_NAME,
        ]);
    }

    public function test_made_to_measure_then_visit_leads_to_slots_a_recap_and_the_tool(): void
    {
        $service = 'Prise de mesures pour une tenue sur mesure';
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->text('Avec plaisir. Pour une tenue sur mesure, il faut prendre vos mesures en boutique : on fixe un rendez-vous ?'))
            ->push($this->text('Très bien. Demain samedi 31 octobre à 10h ou lundi 2 novembre à 15h ?'))
            ->push($this->text("{$service}, lundi 2 novembre à 15h. J'enregistre votre demande ?"))
            ->push($this->toolUse(['service' => $service, 'requested_date' => '2026-11-02', 'requested_time' => '15h']))
            ->push($this->text('Votre demande est enregistrée. Le créneau vous sera confirmé très vite.')),
        ]);

        $this->turn('Bonjour, je veux une tenue sur mesure');
        $this->turn("J'aimerais passer voir");
        $slots = $this->turn('Lundi 15h');
        $this->assertStringContainsString("J'enregistre votre demande ?", $slots['answer']);
        $this->assertSame(0, Appointment::count());

        $result = $this->turn('Oui');

        $this->assertFalse($result['should_escalate']);
        $appointment = Appointment::sole();
        $this->assertSame($service, $appointment->service);
        $this->assertSame('2026-11-02', $appointment->requested_date->format('Y-m-d'));
        $this->assertSame('15h', $appointment->requested_time);
        // Nom WhatsApp repris sans le demander, et aucun prix inventé.
        $this->assertSame(self::WHATSAPP_NAME, $appointment->customer_name);
        $this->assertNull($appointment->price);
        $this->assertNull($appointment->product_id);

        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();
        $prompt = $this->systemPrompt($requests[0]);
        $this->assertStringContainsString('Mène toi-même la prise de rendez-vous : dès que le client veut une tenue sur mesure', $prompt);
        $this->assertStringContainsString('Une seule question par message. Ne demande pas « quel jour et à quelle heure ? »', $prompt);
        $this->assertStringContainsString('« prise de mesures pour 3 ensembles sur mesure »', $prompt);
        $this->assertStringContainsString('Ne repose jamais une question à laquelle le client a déjà répondu', $prompt);
        $this->assertStringContainsString("# Créneaux à proposer\nPendant les horaires d'ouverture : ".self::SLOTS.'.', $prompt);
        $this->assertStringContainsString('Nom de profil WhatsApp : « '.self::WHATSAPP_NAME.' ». Utilise-le uniquement pour le champ customer_name', $prompt);
        // La règle sur le nom reste : jamais pour s'adresser au client.
        $this->assertStringContainsString("ne l'écris jamais au client", $prompt);

        $tool = collect($requests[0]['tools'])->firstWhere('name', 'create_appointment');
        $this->assertSame(['service', 'requested_date', 'requested_time'], $tool['input_schema']['required']);
        $this->assertStringContainsString('décrite librement pour une prise de mesures', $tool['input_schema']['properties']['service']['description']);
    }

    public function test_three_replies_about_an_appointment_without_the_tool_log_a_warning_without_customer_data(): void
    {
        Log::spy();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->text('Vous pouvez passer en boutique quand vous voulez.'))
            ->push($this->text('Pour un rendez-vous, dites-moi quand vous êtes disponible.'))
            ->push($this->text('Quel créneau vous arrange ?')),
        ]);

        $this->turn('Je veux une tenue sur mesure');
        $this->turn("J'aimerais passer voir");
        Log::shouldNotHaveReceived('warning', ['AIResponseService: rendez-vous évoqué sans appel à create_appointment', \Mockery::any()]);

        $this->turn('Je ne sais pas encore');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === 'AIResponseService: rendez-vous évoqué sans appel à create_appointment'
                && $context === ['business_id' => $this->business->id, 'conversation_id' => $this->conversationId, 'turns' => 3];
        });
    }

    public function test_slots_come_from_free_form_opening_hours(): void
    {
        $friday = CarbonImmutable::parse('2026-10-30 10:00:00');
        $describe = fn ($hours) => array_map(fn ($slot) => OpeningHours::describe($slot, $friday), OpeningHours::suggestSlots($hours, $friday));

        $this->assertSame(['demain samedi 31 octobre à 10h', 'lundi 2 novembre à 15h'], $describe(['lundi-samedi' => '9h - 19h', 'dimanche' => 'fermé']));
        // Lundi fermé, samedi ouvert : le samedi d'abord, puis le premier autre jour.
        $this->assertSame(['demain samedi 31 octobre à 9h', 'dimanche 1 novembre à 13h'], $describe(['mardi-samedi' => '8h - 19h', 'dimanche' => '10h - 15h', 'lundi' => 'fermé']));
        // Texte libre avec une pause à midi ; 15h tombe dans la plage de l'après-midi.
        $this->assertSame(['lundi 2 novembre à 9h', 'mardi 3 novembre à 15h'], $describe('Lundi au vendredi 8h-12h / 14h-18h ; samedi et dimanche fermés'));
        $this->assertSame(['demain samedi 31 octobre à 9h', 'dimanche 1 novembre à 15h'], $describe('Tous les jours 08:00-20:00'));
        $this->assertSame([], $describe('Sur rendez-vous'));
        $this->assertSame([], $describe(null));
    }

    public function test_without_readable_hours_the_model_is_told_to_propose_concrete_times(): void
    {
        DB::table('businesses')->where('id', $this->business->id)->update(['opening_hours' => null]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->text('Demain à 10h ou lundi à 15h ?'))]);

        $this->turn("J'aimerais passer voir");

        $prompt = $this->systemPrompt(Http::recorded()->first()[0]->data());
        $this->assertStringContainsString('Aucun créneau calculé : propose deux moments concrets', $prompt);
    }

    /**
     * Un tour de conversation : message du client enregistré, réponse de l'assistant, puis la
     * réponse enregistrée comme le fait SendCustomerReply.
     *
     * @return array<string, mixed>
     */
    private function turn(string $message): array
    {
        $this->travel(1)->minutes();
        $this->message('inbound', 'customer', $message);

        $result = (new FixedContextAIResponseService('NEEB-RATA MARKET, boutique de mode à Ouagadougou. Couture sur mesure.'))
            ->answer(Business::findOrFail($this->business->id), $message, $this->conversationId);

        $this->travel(10)->seconds();
        $this->message('outbound', 'ai', (string) $result['answer'], $result['tool_trace'] ?? []);

        return $result;
    }

    private function message(string $direction, string $sender, string $content, array $metadata = []): void
    {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $this->conversationId, 'direction' => $direction,
            'sender_type' => $sender, 'content' => $content, 'metadata' => $metadata === [] ? null : json_encode($metadata),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function toolUse(array $input): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'create_appointment', 'input' => $input]]];
    }
}
