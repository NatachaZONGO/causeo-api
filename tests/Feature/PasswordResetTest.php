<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    private const SAME_ANSWER = 'Si un compte existe avec cet e-mail, vous allez recevoir un lien pour réinitialiser votre mot de passe.';

    private const INVALID_LINK = 'Ce lien de réinitialisation est invalide ou a expiré. Demandez un nouveau lien depuis la page « Mot de passe oublié ».';

    private User $user;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Vraies migrations : users et password_reset_tokens, tokens Sanctum, et
        // businesses (chargés à la connexion).
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_04_033251_create_personal_access_tokens_table.php',
            '2026_09_04_042020_create_businesses_table.php',
            '2026_09_11_162450_add_google_id_to_users_table.php',
            '2026_09_11_200158_add_is_admin_to_users_table.php',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}"))->up();
        }

        config(['app.frontend_url' => 'https://dashboard.example.test']);
        Notification::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });

        $this->user = User::forceCreate(['name' => 'Awa', 'email' => 'awa@example.test', 'password' => Hash::make('ancien-mot-de-passe')]);
    }

    public function test_known_and_unknown_emails_get_the_same_answer(): void
    {
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'awa@example.test']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'personne@example.test']);

        $known->assertOk()->assertExactJson(['message' => self::SAME_ANSWER]);
        $unknown->assertOk()->assertExactJson(['message' => self::SAME_ANSWER]);

        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);

        // Le jeton est stocké haché, jamais en clair.
        $row = DB::table('password_reset_tokens')->sole();
        $this->assertSame('awa@example.test', $row->email);
        $this->assertStringStartsWith('$2y$', $row->token);

        // Une adresse mal formée reste une erreur de validation, sans rien révéler.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'pas-un-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_reset_email_is_in_french_with_the_dashboard_link(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'awa@example.test'])->assertOk();

        Notification::assertSentTo($this->user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) {
            $mail = $notification->toMail($this->user);
            $url = $notification->url($this->user);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return $mail->subject === 'Réinitialisation de votre mot de passe Causeo'
                && str_starts_with($url, 'https://dashboard.example.test/reset-password?token=')
                && $query['email'] === 'awa@example.test'
                && $mail->actionUrl === $url
                && in_array('Ce lien est valable 60 minutes et ne peut servir qu\'une fois.', $mail->outroLines, true)
                && in_array('Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.', $mail->outroLines, true)
                // Le jeton du lien est celui que le broker accepte.
                && Password::tokenExists($this->user, $query['token']);
        });
    }

    public function test_requests_are_limited_per_email_and_per_ip(): void
    {
        // 3 demandes pour la même adresse, depuis trois IP : la 4e est refusée.
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/forgot-password', ['email' => 'awa@example.test'])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'AWA@example.test'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Trop de demandes de réinitialisation. Réessayez dans 10 minutes.');

        // 3 demandes depuis la même IP, pour trois adresses : la 4e est refusée, même inconnue.
        foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $email) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
                ->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'd@example.test'])
            ->assertStatus(429);

        // Après 10 minutes, la demande repasse.
        $this->travel(11)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'awa@example.test'])->assertOk();
    }

    public function test_reset_changes_the_password_and_logs_out_every_device(): void
    {
        $this->user->createToken('téléphone');
        $this->user->createToken('ordinateur');
        $token = Password::createToken($this->user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'awa@example.test',
            'password' => 'nouveau-mot-de-passe',
            'password_confirmation' => 'nouveau-mot-de-passe',
        ])->assertOk()
            ->assertJsonPath('message', 'Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.');

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        Notification::assertSentTo($this->user, PasswordChangedNotification::class, function (PasswordChangedNotification $notification) {
            $mail = $notification->toMail($this->user);

            return $mail->subject === 'Votre mot de passe a été modifié'
                && $mail->actionUrl === 'https://dashboard.example.test/auth/login';
        });

        $this->postJson('/api/v1/auth/login', ['email' => 'awa@example.test', 'password' => 'nouveau-mot-de-passe'])->assertOk();
    }

    public function test_used_token_cannot_be_reused(): void
    {
        $token = Password::createToken($this->user);
        $this->reset($token, 'nouveau-mot-de-passe')->assertOk();

        $this->reset($token, 'encore-un-autre')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token' => self::INVALID_LINK]);
        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $this->user->fresh()->password));
        Notification::assertSentTimes(PasswordChangedNotification::class, 1);
    }

    public function test_expired_or_wrong_token_is_refused_with_a_clear_message(): void
    {
        $token = Password::createToken($this->user);

        $this->travel(61)->minutes();
        $this->reset($token, 'nouveau-mot-de-passe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token' => self::INVALID_LINK]);

        $this->reset('faux-jeton', 'nouveau-mot-de-passe')
            ->assertJsonValidationErrors(['token' => self::INVALID_LINK]);
        // Adresse inconnue : même message.
        $this->reset($token, 'nouveau-mot-de-passe', 'personne@example.test')
            ->assertJsonValidationErrors(['token' => self::INVALID_LINK]);

        $this->assertTrue(Hash::check('ancien-mot-de-passe', $this->user->fresh()->password));
        Notification::assertNotSentTo($this->user, PasswordChangedNotification::class);
    }

    public function test_password_must_have_8_characters_and_be_confirmed(): void
    {
        $token = Password::createToken($this->user);

        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'awa@example.test', 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'Le mot de passe doit contenir au moins 8 caractères.']);
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'awa@example.test', 'password' => 'nouveau-mot-de-passe', 'password_confirmation' => 'autre-chose'])
            ->assertJsonValidationErrors(['password' => 'La confirmation du mot de passe ne correspond pas.']);

        // Le jeton reste utilisable.
        $this->assertTrue(Password::tokenExists($this->user, $token));
    }

    public function test_google_account_can_set_a_password(): void
    {
        $google = User::forceCreate([
            'name' => 'Fatou', 'email' => 'fatou@example.test', 'google_id' => 'google-123',
            'password' => Hash::make(Str::random(24)),
        ]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'fatou@example.test'])->assertOk();

        $token = null;
        Notification::assertSentTo($google, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($google, &$token) {
            parse_str((string) parse_url($notification->url($google), PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        $this->reset($token, 'mot-de-passe-fatou', 'fatou@example.test')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'fatou@example.test', 'password' => 'mot-de-passe-fatou'])->assertOk();
        $this->assertSame('google-123', $google->fresh()->google_id);
    }

    public function test_logs_never_contain_the_token_or_the_email(): void
    {
        // L'envoi échoue : l'erreur est journalisée, sans jeton ni adresse.
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('550 mailbox awa@example.test unavailable'));

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'awa@example.test'])->assertOk();
        $token = Password::createToken($this->user);
        $this->reset($token, 'nouveau-mot-de-passe')->assertOk();

        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('awa@example.test', $line);
            $this->assertStringNotContainsString($token, $line);
        }
    }

    private function reset(string $token, string $password, string $email = 'awa@example.test'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }
}
