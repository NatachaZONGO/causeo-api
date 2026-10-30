<?php

namespace Tests\Feature;

use App\Models\LeadFollowup;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Suivi des inscrits par le super admin : étape atteinte, usage du bot, formule, relances,
 * filtres et export CSV, réservés aux admins.
 */
class AdminSignupsTest extends TestCase
{
    use CreatesConversationSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-30 10:00:00');
        $this->createConversationSchema();
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable();
            $table->string('whatsapp_phone')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('google_id')->nullable();
            $table->string('remember_token')->nullable();
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('title');
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_09_04_033251_create_personal_access_tokens_table.php'))->up();
        (require base_path('database/migrations/2026_10_30_110000_add_last_login_at_to_users.php'))->up();
        (require base_path('database/migrations/2026_10_30_120000_create_lead_followups_table.php'))->up();
        (require base_path('database/migrations/2026_10_30_130000_add_do_not_contact_to_users.php'))->up();

        $this->admin = $this->user('Admin', 'admin@causeo.test', ['is_admin' => true]);
        Sanctum::actingAs($this->admin);
    }

    public function test_each_signup_is_at_the_most_advanced_stage_reached(): void
    {
        $this->user('Inscrit seul', 'registered@example.test');
        $this->business($this->user('Avec entreprise', 'business@example.test'));
        $this->document($this->business($this->user('Avec document', 'document@example.test')));
        $this->product($this->business($this->user('Avec article', 'product@example.test')));
        $this->business($this->user('Connecté', 'connected@example.test'), connected: true);
        // Réponse du bot il y a 10 jours : hors fenêtre, reste « connecté ».
        $this->botReply($this->business($this->user('Bot ancien', 'old-bot@example.test'), connected: true), '2026-10-20 10:00:00');
        $this->botReply($this->business($this->user('Bot actif', 'bot@example.test'), connected: true), '2026-10-28 10:00:00');
        $this->assignPlan($this->business($this->user('Payant', 'paying@example.test')), Plan::STARTER);
        // Ni l'essai ni la formule Interne ne sont payants.
        $this->trial($this->business($this->user('En essai', 'trial@example.test')), '2026-11-05 10:00:00');
        $this->assignPlan($this->business($this->user('Interne', 'internal@example.test')), Plan::INTERNAL);
        // L'étape suit la première entreprise de l'inscrit.
        $multi = $this->user('Deux entreprises', 'multi@example.test');
        $this->business($multi, createdAt: '2026-10-01 10:00:00');
        $this->business($multi, connected: true, createdAt: '2026-10-02 10:00:00');

        $stages = collect($this->getJson('/api/v1/admin/signups')->assertOk()->json('data'))->pluck('stage', 'email')->sortKeys()->all();

        $this->assertSame([
            'bot@example.test' => 'bot_active',
            'business@example.test' => 'business_created',
            'connected@example.test' => 'whatsapp_connected',
            'document@example.test' => 'info_added',
            'internal@example.test' => 'business_created',
            'multi@example.test' => 'business_created',
            'old-bot@example.test' => 'whatsapp_connected',
            'paying@example.test' => 'paying',
            'product@example.test' => 'info_added',
            'registered@example.test' => 'registered',
            'trial@example.test' => 'business_created',
        ], $stages);
        $this->assertArrayNotHasKey('admin@causeo.test', $stages);
    }

    public function test_a_signup_carries_profile_business_billing_usage_and_latest_followup(): void
    {
        $awa = $this->user('Awa Ouédraogo', 'awa@example.test', [
            'phone' => '+226 70 12 34 56', 'whatsapp_phone' => '+226 76 00 00 00', 'city' => 'Ouagadougou', 'country' => 'BF',
            'created_at' => '2026-10-25 08:00:00', 'last_login_at' => '2026-10-29 18:00:00',
        ]);
        $business = $this->business($awa, connected: true, name: 'Boutique Awa', type: 'boutique');
        $this->trial($business, '2026-11-02 10:00:00');
        $this->botReply($business, '2026-10-29 10:00:00');
        $this->botReply($business, '2026-10-30 09:00:00');
        $this->botReply($business, '2026-10-01 09:00:00'); // hors fenêtre
        $this->followup($awa, ['note' => 'Appelée, rappeler', 'contacted_at' => '2026-10-27 09:00:00', 'next_followup_at' => '2026-10-29'], '2026-10-27 09:00:00');
        $this->followup($awa, ['contacted_at' => '2026-10-29 11:00:00', 'next_followup_at' => '2026-11-03'], '2026-10-29 11:00:00');

        $signup = collect($this->getJson('/api/v1/admin/signups')->assertOk()->json('data'))->firstWhere('email', 'awa@example.test');

        $this->assertSame('Awa Ouédraogo', $signup['name']);
        $this->assertSame('+226 70 12 34 56', $signup['phone']);
        $this->assertSame('+226 76 00 00 00', $signup['whatsapp_phone']);
        $this->assertSame('Ouagadougou', $signup['city']);
        $this->assertSame('BF', $signup['country']);
        $this->assertSame('2026-10-25T08:00:00+00:00', $signup['created_at']);
        $this->assertSame('2026-10-29T18:00:00+00:00', $signup['last_login_at']);
        $this->assertSame(['id' => $business, 'name' => 'Boutique Awa', 'type' => 'boutique', 'activity_profile' => 'boutique'], $signup['business']);
        $this->assertSame('bot_active', $signup['stage']);
        $this->assertSame('trialing', $signup['billing']['status']);
        $this->assertSame('pro', $signup['billing']['plan']['slug']);
        $this->assertSame('2026-11-02T10:00:00+00:00', $signup['billing']['trial_ends_at']);
        $this->assertSame(2, $signup['messages_7d']);
        $this->assertSame('2026-10-29T11:00:00+00:00', $signup['last_contacted_at']);
        $this->assertSame('2026-11-03', $signup['next_followup_at']);
        // La dernière relance n'a pas de note : la note précédente reste affichée.
        $this->assertSame('Appelée, rappeler', $signup['last_note']);
        $this->assertArrayNotHasKey('password', $signup);
    }

    public function test_signups_are_listed_most_recent_first(): void
    {
        $this->user('Ancien', 'old@example.test', ['created_at' => '2026-10-01 10:00:00']);
        $this->user('Récent', 'new@example.test', ['created_at' => '2026-10-29 10:00:00']);

        $emails = array_column($this->getJson('/api/v1/admin/signups')->assertOk()->json('data'), 'email');

        $this->assertSame(['new@example.test', 'old@example.test'], $emails);
    }

    public function test_the_list_is_filtered_by_stage_search_trial_followup_and_signup_date(): void
    {
        $awa = $this->user('Awa Ouédraogo', 'awa@example.test', ['phone' => '+226 70 12 34 56', 'created_at' => '2026-10-05 10:00:00']);
        $this->trial($this->business($awa), '2026-11-03 10:00:00');
        $fatou = $this->user('Fatou Traoré', 'fatou@shop.test', ['created_at' => '2026-10-20 10:00:00']);
        $this->trial($this->business($fatou, connected: true), '2026-11-20 10:00:00');
        $this->followup($fatou, ['next_followup_at' => '2026-10-30']);
        $ali = $this->user('Ali Kaboré', 'ali@example.test', ['whatsapp_phone' => '+22676999999', 'created_at' => '2026-10-28 10:00:00']);
        $this->followup($ali, ['next_followup_at' => '2026-11-15']);

        $this->assertEmails(['fatou@shop.test'], 'stage=whatsapp_connected');
        $this->assertEmails(['awa@example.test'], 'stage=business_created');
        $this->assertEmails(['ali@example.test'], 'stage=registered');
        $this->assertEmails(['awa@example.test'], 'search=ouédraogo');
        $this->assertEmails(['fatou@shop.test'], 'search=SHOP.test');
        $this->assertEmails(['awa@example.test'], 'search=70123456');
        $this->assertEmails(['ali@example.test'], 'search=76999');
        $this->assertEmails(['awa@example.test'], 'trial_ending=1');
        $this->assertEmails(['fatou@shop.test'], 'followup_due=1');
        $this->assertEmails(['fatou@shop.test', 'awa@example.test'], 'from=2026-10-05&to=2026-10-20');
        $this->assertEmails(['ali@example.test'], 'from=2026-10-21');

        $this->getJson('/api/v1/admin/signups?stage=lost')->assertUnprocessable()->assertJsonValidationErrors('stage');
    }

    public function test_an_admin_records_followups_and_reads_their_history(): void
    {
        $awa = $this->user('Awa', 'awa@example.test');

        $this->postJson("/api/v1/admin/signups/{$awa->id}/followups", [
            'note' => 'Premier appel, pas de réponse',
            'contacted_at' => '2026-10-28T09:00:00Z',
            'next_followup_at' => '2026-10-31',
        ])->assertCreated()->assertJsonPath('followup.note', 'Premier appel, pas de réponse')
            ->assertJsonPath('followup.next_followup_at', '2026-10-31')
            ->assertJsonPath('followup.author.name', 'Admin');

        $this->travel(1)->hours();
        $this->postJson("/api/v1/admin/signups/{$awa->id}/followups", ['note' => 'Rappelée, intéressée'])->assertCreated();
        $this->postJson("/api/v1/admin/signups/{$awa->id}/followups", ['next_followup_at' => '31/10/2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('next_followup_at');

        $history = $this->getJson("/api/v1/admin/signups/{$awa->id}/followups")->assertOk()->json('data');

        $this->assertSame(['Rappelée, intéressée', 'Premier appel, pas de réponse'], array_column($history, 'note'));
        $this->assertSame($this->admin->id, LeadFollowup::query()->first()->created_by);
        $this->assertSame('Rappelée, intéressée', collect($this->getJson('/api/v1/admin/signups')->json('data'))->firstWhere('email', 'awa@example.test')['last_note']);
    }

    public function test_the_csv_export_has_the_same_columns_and_filters(): void
    {
        $awa = $this->user('Awa Ouédraogo', 'awa@example.test', ['phone' => '+226 70 12 34 56', 'created_at' => '2026-10-05 10:00:00']);
        $this->assignPlan($this->business($awa, name: 'Boutique Awa'), Plan::STARTER);
        $this->followup($awa, ['note' => '=HYPERLINK("x")']);
        $this->user('Ali', 'ali@example.test');

        $response = $this->get('/api/v1/admin/signups/export?stage=paying')->assertOk();

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename=inscrits-2026-10-30.csv', $response->headers->get('Content-Disposition'));
        $lines = array_map(fn ($line) => str_getcsv($line, ';'), explode("\n", trim(substr($response->streamedContent(), 3))));

        $this->assertCount(2, $lines);
        $this->assertSame('Nom', $lines[0][0]);
        $this->assertSame('Ne plus relancer', end($lines[0]));
        $row = array_combine($lines[0], $lines[1]);
        $this->assertSame('Awa Ouédraogo', $row['Nom']);
        $this->assertSame('+226 70 12 34 56', $row['Téléphone']);
        $this->assertSame('Boutique Awa', $row['Entreprise']);
        $this->assertSame('paying', $row['Étape']);
        $this->assertSame('Starter', $row['Formule']);
        // Pas de formule exécutée par le tableur.
        $this->assertSame("'=HYPERLINK(\"x\")", $row['Dernière note']);
        $this->assertSame('Non', $row['Ne plus relancer']);
    }

    public function test_counts_cover_every_signup_whatever_the_filters(): void
    {
        $this->user('Inscrit', 'registered@example.test');
        $this->user('Inscrit 2', 'registered2@example.test');
        $connected = $this->user('Connecté', 'connected@example.test');
        $this->trial($this->business($connected, connected: true), '2026-11-02 10:00:00');
        $this->followup($connected, ['next_followup_at' => '2026-10-29']);
        $this->assignPlan($this->business($this->user('Payant', 'paying@example.test')), Plan::STARTER);

        $response = $this->getJson('/api/v1/admin/signups?stage=paying')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame([
            'registered' => 2,
            'business_created' => 0,
            'info_added' => 0,
            'whatsapp_connected' => 1,
            'bot_active' => 0,
            'paying' => 1,
            'trial_ending' => 1,
            'followup_due' => 1,
        ], $response->json('counts'));
    }

    public function test_a_signup_marked_do_not_contact_leaves_the_due_followups(): void
    {
        $awa = $this->user('Awa', 'awa@example.test');
        $this->followup($awa, ['next_followup_at' => '2026-10-29']);

        $this->patchJson("/api/v1/admin/signups/{$awa->id}", ['do_not_contact' => true])
            ->assertOk()->assertJsonPath('do_not_contact', true);

        $response = $this->getJson('/api/v1/admin/signups')->assertOk();
        $this->assertTrue($response->json('data.0.do_not_contact'));
        $this->assertSame(0, $response->json('counts.followup_due'));
        $this->assertEmails([], 'followup_due=1');
        $this->assertStringContainsString(';Oui', $this->get('/api/v1/admin/signups/export')->streamedContent());

        $this->patchJson("/api/v1/admin/signups/{$awa->id}", ['do_not_contact' => false])->assertOk();
        $this->assertEmails(['awa@example.test'], 'followup_due=1');

        $this->patchJson("/api/v1/admin/signups/{$awa->id}", [])->assertUnprocessable()->assertJsonValidationErrors('do_not_contact');
        $this->patchJson("/api/v1/admin/signups/{$this->admin->id}", ['do_not_contact' => true])->assertNotFound();
    }

    public function test_the_number_of_queries_does_not_grow_with_the_page(): void
    {
        // Une formule payante et une Gratuite : la formule Gratuit est lue une fois par requête.
        $this->assignPlan($this->business($this->user('U0', 'u0@example.test')), Plan::STARTER);
        $this->assignPlan($this->business($this->user('U1', 'u1@example.test')), Plan::FREE);
        $few = $this->countQueries(fn () => $this->getJson('/api/v1/admin/signups')->assertOk());

        for ($i = 2; $i <= 12; $i++) {
            $user = $this->user("U{$i}", "u{$i}@example.test");
            $business = $this->business($user, connected: true);
            $this->botReply($business, '2026-10-29 10:00:00');
            $this->followup($user, ['note' => 'Relance']);
            $i % 2 === 0 ? $this->trial($business, '2026-11-05 10:00:00') : $this->assignPlan($business, Plan::FREE);
        }
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/admin/signups')->assertOk());

        $this->assertSame($few, $many);
    }

    public function test_no_signup_data_is_logged(): void
    {
        $this->assignPlan($this->business($this->user('Awa', 'awa@example.test')), Plan::STARTER);
        Log::spy();

        $this->getJson('/api/v1/admin/signups')->assertOk();
        $this->get('/api/v1/admin/signups/export')->assertOk()->streamedContent();

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_signups_are_reserved_to_admins(): void
    {
        $owner = $this->user('Gérant', 'owner@example.test');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/admin/signups')->assertForbidden();
        $this->getJson('/api/v1/admin/signups/export')->assertForbidden();
        $this->getJson("/api/v1/admin/signups/{$owner->id}/followups")->assertForbidden();
        $this->postJson("/api/v1/admin/signups/{$owner->id}/followups", ['note' => 'x'])->assertForbidden();
        $this->patchJson("/api/v1/admin/signups/{$owner->id}", ['do_not_contact' => true])->assertForbidden();
        $this->assertFalse($owner->refresh()->do_not_contact);
        $this->assertSame(0, LeadFollowup::query()->count());
    }

    public function test_login_records_the_last_login_date(): void
    {
        $awa = $this->user('Awa', 'awa@example.test', ['password' => 'secret-password', 'updated_at' => '2026-10-01 10:00:00']);

        $this->postJson('/api/v1/auth/login', ['email' => 'awa@example.test', 'password' => 'secret-password'])->assertOk();

        $awa->refresh();
        $this->assertSame('2026-10-30 10:00:00', $awa->last_login_at->toDateTimeString());
        $this->assertSame('2026-10-01 10:00:00', $awa->updated_at->toDateTimeString());
    }

    public function test_google_login_records_the_last_login_date(): void
    {
        $awa = $this->user('Awa', 'awa@example.test');
        config(['app.frontend_url' => 'https://app.causeo.example']);
        Socialite::shouldReceive('driver->user')->andReturn(
            (new SocialiteUser())->map(['id' => 'g-1', 'name' => 'Awa', 'email' => 'awa@example.test']),
        );

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertSame('2026-10-30 10:00:00', $awa->refresh()->last_login_at->toDateTimeString());
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertEmails(array $expected, string $query): void
    {
        $this->assertSame($expected, array_column($this->getJson("/api/v1/admin/signups?{$query}")->assertOk()->json('data'), 'email'), $query);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(string $name, string $email, array $attributes = []): User
    {
        return User::forceCreate(['name' => $name, 'email' => $email, 'password' => 'x', ...$attributes]);
    }

    private function business(User $user, bool $connected = false, string $name = 'Boutique', string $type = 'boutique', string $createdAt = '2026-10-10 10:00:00'): string
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
            'modules' => '[]',
            'whatsapp_verified' => $connected,
            'whatsapp_phone_number_id' => $connected ? 'pnid-'.$id : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function document(string $businessId): void
    {
        DB::table('documents')->insert(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'title' => 'Tarifs']);
    }

    private function product(string $businessId): void
    {
        DB::table('products')->insert(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Pagne', 'base_price' => 5000]);
    }

    private function botReply(string $businessId, string $at): void
    {
        $conversationId = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $conversationId, 'business_id' => $businessId, 'customer_phone' => '22670000000', 'created_at' => $at, 'updated_at' => $at]);
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $conversationId, 'direction' => 'outbound',
            'sender_type' => 'ai', 'content' => 'Bonjour', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function trial(string $businessId, string $endsAt): void
    {
        Subscription::create([
            'business_id' => $businessId,
            'plan_id' => Plan::bySlug(Plan::PRO)->id,
            'status' => 'trialing',
            'trial_started_at' => now(),
            'trial_ends_at' => $endsAt,
            'trial_unit_limit' => 300,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function followup(User $user, array $attributes, ?string $at = null): void
    {
        $followup = new LeadFollowup(['user_id' => $user->id, 'created_by' => $this->admin->id, ...$attributes]);
        $followup->forceFill(['created_at' => $at ?? now(), 'updated_at' => $at ?? now()])->save();
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
