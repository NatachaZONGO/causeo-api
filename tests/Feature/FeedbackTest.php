<?php

namespace Tests\Feature;

use App\Mail\OwnerNotificationMail;
use App\Models\Business;
use App\Models\Feedback;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private User $admin;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-12 10:00:00');
        $this->createConversationSchema();
        (require base_path('database/migrations/2026_10_12_110000_create_feedback_table.php'))->up();
        Storage::fake('supabase_documents');
        Mail::fake();
        config(['app.frontend_url' => 'https://dashboard.example.test']);

        $this->owner = User::forceCreate(['name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x']);
        $this->admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);
        $this->business = $this->makeBusiness($this->owner, 'Joyce Boutique');
    }

    // --- Envoi par le gérant -------------------------------------------------------------

    public function test_owner_sends_feedback_with_context_and_screenshot_and_admins_are_notified(): void
    {
        Sanctum::actingAs($this->owner);
        $message = "Le bouton « Enregistrer » ne fait rien sur la page des produits.\nJ'ai essayé deux fois depuis mon téléphone, puis depuis l'ordinateur, sans succès. Merci de regarder !";

        $response = $this->post('/api/v1/feedback', [
            'type' => 'bug',
            'message' => $message,
            'page' => 'https://dashboard.example.test/dashboard/products',
            'business_id' => $this->business->id,
            'context' => ['browser' => 'Chrome 129 / Android 14', 'screen' => '412x915', 'app_version' => '1.8.0', 'ignored' => 'x'],
            'screenshot' => UploadedFile::fake()->image('capture.png', 400, 800),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('feedback.type', 'bug')
            ->assertJsonPath('feedback.status', 'nouveau')
            ->assertJsonPath('feedback.has_screenshot', true)
            ->assertJsonPath('feedback.context', ['browser' => 'Chrome 129 / Android 14', 'screen' => '412x915', 'app_version' => '1.8.0']);
        $this->assertArrayNotHasKey('screenshot_path', $response->json('feedback'));

        $feedback = Feedback::sole();
        $this->assertSame($this->owner->id, $feedback->user_id);
        $this->assertSame($this->business->id, $feedback->business_id);
        $this->assertStringStartsWith("feedback/{$this->owner->id}/", $feedback->screenshot_path);
        $this->assertStringEndsWith('.png', $feedback->screenshot_path);
        Storage::disk('supabase_documents')->assertExists($feedback->screenshot_path);

        // Cloche des admins : type et début du message, sur une seule ligne.
        $notification = Notification::query()->where('audience', Notification::ADMIN)->sole();
        $this->assertSame('feedback', $notification->type);
        $this->assertSame('Nouveau retour : Bug', $notification->title);
        $this->assertStringStartsWith('Joyce : Le bouton « Enregistrer » ne fait rien sur la page des produits. J\'ai essayé', $notification->body);
        $this->assertStringEndsWith('…', $notification->body);
        $this->assertSame(['kind' => 'feedback_submitted', 'feedback_id' => $feedback->id, 'feedback_type' => 'bug'], $notification->data);

        // E-mail aux admins seulement.
        Mail::assertQueued(OwnerNotificationMail::class, 1);
        Mail::assertQueued(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->hasTo('admin@example.test')
            && $mail->title === 'Nouveau retour : Bug'
            && $mail->actionUrl === 'https://dashboard.example.test/admin/feedback');

        // Le gérant ne voit pas la notification des admins.
        $this->getJson("/api/v1/businesses/{$this->business->id}/notifications")->assertJsonPath('total', 0);
    }

    public function test_feedback_without_business_still_notifies_admins_and_uses_the_user_agent(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/feedback', ['type' => 'suggestion', 'message' => 'Un mode sombre serait super.'], ['User-Agent' => 'Mozilla/5.0 Test'])
            ->assertCreated()
            ->assertJsonPath('feedback.business_id', null)
            ->assertJsonPath('feedback.has_screenshot', false)
            ->assertJsonPath('feedback.context', ['browser' => 'Mozilla/5.0 Test']);

        $notification = Notification::query()->where('audience', Notification::ADMIN)->sole();
        $this->assertNull($notification->business_id);
        $this->assertSame('Nouveau retour : Suggestion', $notification->title);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.business', null);
    }

    public function test_feedback_is_validated(): void
    {
        Sanctum::actingAs($this->owner);
        $other = $this->makeBusiness($this->admin, 'Autre');

        $this->postJson('/api/v1/feedback', ['type' => 'plainte', 'message' => 'abc', 'business_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'message', 'business_id']);

        foreach ([
            UploadedFile::fake()->create('capture.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->image('capture.gif'),
            UploadedFile::fake()->image('capture.jpg')->size(5121),
        ] as $file) {
            $this->post('/api/v1/feedback', ['type' => 'bug', 'message' => 'Ça ne marche pas.', 'screenshot' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('screenshot');
        }

        $this->post('/api/v1/feedback', ['type' => 'bug', 'message' => 'Ça ne marche pas.', 'screenshot' => UploadedFile::fake()->image('capture.webp')->size(5000)], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(1, Feedback::count());
    }

    public function test_owner_can_send_10_feedbacks_per_hour(): void
    {
        Sanctum::actingAs($this->owner);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/feedback', ['type' => 'question', 'message' => "Question numéro {$i}"])->assertCreated();
        }

        $this->postJson('/api/v1/feedback', ['type' => 'question', 'message' => 'Question numéro 11'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Vous avez envoyé beaucoup de retours. Réessayez dans 60 minutes.');
        $this->assertSame(10, Feedback::count());

        // Les autres gérants ne sont pas concernés, et la limite se lève au bout d'une heure.
        $other = User::forceCreate(['name' => 'Fatou', 'email' => 'fatou@example.test', 'password' => 'x']);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/feedback', ['type' => 'question', 'message' => 'Bonjour Causeo'])->assertCreated();

        $this->travel(61)->minutes();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/feedback', ['type' => 'question', 'message' => 'Question numéro 11'])->assertCreated();
    }

    // --- Historique du gérant --------------------------------------------------------------

    public function test_owner_sees_only_their_own_feedback_with_status_and_reply(): void
    {
        $mine = $this->feedback($this->owner, ['status' => 'resolu', 'admin_reply' => 'Corrigé, merci !', 'replied_at' => now()]);
        $this->travel(1)->minutes();
        $this->feedback($this->owner, ['type' => 'suggestion', 'message' => 'Export Excel des commandes']);
        $this->feedback($this->admin, ['message' => 'Retour d\'un autre utilisateur']);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/me/feedback')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.message', 'Export Excel des commandes')
            ->assertJsonPath('data.0.status', 'nouveau')
            ->assertJsonPath('data.1.id', $mine->id)
            ->assertJsonPath('data.1.status', 'resolu')
            ->assertJsonPath('data.1.admin_reply', 'Corrigé, merci !')
            ->assertJsonPath('data.1.replied_at', now()->subMinute()->toJSON())
            ->assertJsonPath('data.1.business.name', 'Joyce Boutique')
            ->assertJsonMissingPath('data.1.screenshot_path');
    }

    // --- Admin ---------------------------------------------------------------------------------

    public function test_admin_lists_and_filters_feedback(): void
    {
        $bug = $this->feedback($this->owner, ['type' => 'bug', 'message' => 'Le PANIER se vide tout seul']);
        $this->feedback($this->owner, ['type' => 'suggestion', 'message' => 'Mode sombre', 'status' => 'en_cours']);
        $fatou = User::forceCreate(['name' => 'Fatou Ouédraogo', 'email' => 'fatou@salon.test', 'password' => 'x']);
        $salon = $this->feedback($fatou, ['type' => 'question', 'message' => 'Comment ajouter un service ?', 'business_id' => $this->makeBusiness($fatou, 'Salon Fatou')->id]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/feedback')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('new_count', 2)
            ->assertJsonPath('data.0.user.email', 'joyce@example.test');

        $this->getJson('/api/v1/admin/feedback?type=bug')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $bug->id);
        $this->getJson('/api/v1/admin/feedback?status=en_cours')->assertJsonPath('total', 1)->assertJsonPath('data.0.message', 'Mode sombre');
        $this->getJson('/api/v1/admin/feedback?search=panier')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $bug->id);
        $this->getJson('/api/v1/admin/feedback?search=salon')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $salon->id);
        $this->getJson('/api/v1/admin/feedback?search=fatou%40')->assertJsonPath('total', 1);

        // % et _ sont cherchés tels quels.
        $promo = $this->feedback($this->owner, ['type' => 'suggestion', 'message' => 'Une remise de 100% le premier mois']);
        $this->feedback($this->owner, ['type' => 'suggestion', 'message' => 'Une remise de 1000 FCFA']);
        $this->getJson('/api/v1/admin/feedback?search=100%25')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $promo->id);
        $this->getJson('/api/v1/admin/feedback?search=_')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/admin/feedback?type=bug&status=en_cours')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/admin/feedback?status=archive')->assertUnprocessable();
    }

    public function test_admin_reply_notifies_the_owner_by_bell_and_email(): void
    {
        $feedback = $this->feedback($this->owner, ['type' => 'question', 'message' => 'Comment ajouter un produit ?']);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/admin/feedback/{$feedback->id}", ['status' => 'en_cours', 'admin_reply' => 'Allez dans Catalogue puis « Ajouter ».'])
            ->assertOk()
            ->assertJsonPath('feedback.status', 'en_cours')
            ->assertJsonPath('feedback.admin_reply', 'Allez dans Catalogue puis « Ajouter ».')
            ->assertJsonPath('feedback.replied_at', now()->toJSON());

        $notification = Notification::query()->where('audience', Notification::OWNER)->sole();
        $this->assertSame($this->business->id, $notification->business_id);
        $this->assertSame('feedback', $notification->type);
        $this->assertSame('Réponse à votre retour', $notification->title);
        $this->assertSame('Allez dans Catalogue puis « Ajouter ».', $notification->body);
        $this->assertSame(['kind' => 'feedback_replied', 'feedback_id' => $feedback->id], $notification->data);

        Mail::assertQueued(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->hasTo('joyce@example.test')
            && $mail->title === 'Réponse à votre retour'
            && in_array('Réponse de l\'équipe Causeo : Allez dans Catalogue puis « Ajouter ».', $mail->lines, true)
            && $mail->actionUrl === 'https://dashboard.example.test/dashboard/feedback');

        // Changer seulement le statut (hors « résolu ») ou renvoyer la même réponse ne notifie pas.
        $this->travel(5)->minutes();
        $this->patchJson("/api/v1/admin/feedback/{$feedback->id}", ['status' => 'ferme', 'admin_reply' => 'Allez dans Catalogue puis « Ajouter ».'])
            ->assertOk()
            ->assertJsonPath('feedback.replied_at', now()->subMinutes(5)->toJSON());
        $this->assertSame(1, Notification::query()->where('audience', Notification::OWNER)->count());
        Mail::assertQueued(OwnerNotificationMail::class, 1);
    }

    public function test_resolving_notifies_the_owner_once(): void
    {
        $feedback = $this->feedback($this->owner, ['type' => 'bug', 'message' => 'Le panier se vide', 'business_id' => null]);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/admin/feedback/{$feedback->id}", ['status' => 'resolu'])->assertOk()->assertJsonPath('feedback.status', 'resolu');
        $this->patchJson("/api/v1/admin/feedback/{$feedback->id}", ['status' => 'resolu'])->assertOk();

        // Sans business sur le retour : la cloche du premier business du gérant.
        $notification = Notification::query()->where('audience', Notification::OWNER)->sole();
        $this->assertSame($this->business->id, $notification->business_id);
        $this->assertSame('Votre retour a été résolu', $notification->title);
        $this->assertSame('Votre bug a été marqué comme résolu.', $notification->body);
        $this->assertSame('feedback_resolved', $notification->data['kind']);
        Mail::assertQueued(OwnerNotificationMail::class, 1);
        Mail::assertQueued(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->hasTo('joyce@example.test'));
    }

    public function test_admin_downloads_the_screenshot(): void
    {
        Storage::disk('supabase_documents')->put('feedback/1/capture.png', 'png');
        $with = $this->feedback($this->owner, ['screenshot_path' => 'feedback/1/capture.png']);
        $without = $this->feedback($this->owner);

        Sanctum::actingAs($this->admin);
        $this->get("/api/v1/admin/feedback/{$with->id}/screenshot")->assertOk()->assertDownload();
        $this->getJson("/api/v1/admin/feedback/{$without->id}/screenshot")->assertNotFound();
    }

    public function test_admin_routes_are_reserved_to_admins(): void
    {
        $feedback = $this->feedback($this->owner);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/feedback')->assertForbidden();
        $this->patchJson("/api/v1/admin/feedback/{$feedback->id}", ['status' => 'resolu'])->assertForbidden();
        $this->getJson("/api/v1/admin/feedback/{$feedback->id}/screenshot")->assertForbidden();

        $this->assertSame('nouveau', $feedback->fresh()->status);
    }

    public function test_guests_cannot_send_or_read_feedback(): void
    {
        $this->postJson('/api/v1/feedback', ['type' => 'bug', 'message' => 'Ça ne marche pas.'])->assertUnauthorized();
        $this->getJson('/api/v1/me/feedback')->assertUnauthorized();
    }

    // --- Outils ----------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function feedback(User $user, array $attributes = []): Feedback
    {
        return Feedback::create($attributes + [
            'user_id' => $user->id,
            'business_id' => $user->is($this->owner) ? $this->business->id : null,
            'type' => 'bug',
            'message' => 'Quelque chose ne marche pas.',
        ]);
    }

    private function makeBusiness(User $owner, string $name): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $owner->id, 'name' => $name, 'modules' => '[]']);

        return Business::findOrFail($id);
    }
}
