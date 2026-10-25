<?php

namespace Tests\Feature;

use App\Mail\OwnerNotificationMail;
use App\Models\User;
use App\Support\ProductionGuard;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Sauvegarde quotidienne de la base (deploy/backup.sh) : alerte d'échec, tâche de 3 h,
 * réglages du script et garde-fou de la restauration.
 */
class BackupTest extends TestCase
{
    use CreatesConversationSchema;

    public function test_a_failed_backup_emails_the_admins_and_the_configured_addresses(): void
    {
        $this->createConversationSchema();
        Mail::fake();
        User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);
        User::forceCreate(['name' => 'Gérante', 'email' => 'owner@example.test', 'password' => 'x']);

        $this->artisan('causeo:backup-alert', ['reason' => 'étape « pg_dump » en erreur (code 1)', '--email' => ['ops@example.test, admin@example.test', 'pas-une-adresse']])
            ->expectsOutputToContain('Alerte envoyée à 2 destinataire(s).')
            ->assertSuccessful();

        Mail::assertSent(OwnerNotificationMail::class, 2);
        Mail::assertSent(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->hasTo('ops@example.test')
            && $mail->title === 'Échec de la sauvegarde de la base Causeo'
            && $mail->lines[1] === 'Raison : étape « pg_dump » en erreur (code 1)');
        Mail::assertNotSent(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->hasTo('owner@example.test'));
    }

    public function test_the_alert_still_goes_out_when_the_database_is_unreachable(): void
    {
        // Pas de table users : la base est justement en panne.
        Mail::fake();

        $this->artisan('causeo:backup-alert', ['reason' => 'connexion à la base', '--email' => ['ops@example.test']])
            ->expectsOutputToContain('Admins introuvables')
            ->assertSuccessful();

        Mail::assertSent(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->hasTo('ops@example.test'));

        $this->artisan('causeo:backup-alert', ['reason' => 'test'])
            ->expectsOutputToContain('Aucun destinataire')
            ->assertFailed();
    }

    public function test_the_backup_runs_every_night_at_3_with_encryption_and_retention(): void
    {
        $cron = $this->file('deploy/cron/causeo-backup');
        $this->assertMatchesRegularExpression('#^0 3 \* \* \* root /usr/local/sbin/causeo-backup >> /var/log/causeo-backup\.log 2>&1$#m', $cron);

        $script = $this->file('deploy/backup.sh');
        $this->assertStringContainsString('set -Eeuo pipefail', $script);
        $this->assertStringContainsString('umask 077', $script);
        $this->assertStringContainsString('--format=custom --compress=9 --schema=public', $script);
        $this->assertStringContainsString('age --recipient "$AGE_RECIPIENT"', $script);
        $this->assertStringContainsString('RETENTION_DAYS="${RETENTION_DAYS:-14}"', $script);
        $this->assertStringContainsString('causeo:backup-alert', $script);
        // Le dump en clair ne quitte jamais le dossier de travail de root.
        $this->assertStringContainsString('rm -f "$PLAIN"', $script);

        // Les réglages documentés sont ceux que le script exige.
        $example = $this->file('deploy/backup.env.example');
        preg_match('/for name in ([A-Z0-9_ ]+); do/', $script, $required);
        foreach (explode(' ', trim($required[1])) as $name) {
            $this->assertMatchesRegularExpression("/^{$name}=/m", $example, $name);
        }

        $setup = $this->file('deploy/setup.sh');
        $this->assertStringContainsString('"postgresql-client-${PG_CLIENT_MAJOR}" age rclone', $setup);
        $this->assertStringContainsString('/usr/local/sbin/causeo-backup', $setup);
    }

    public function test_restore_refuses_the_production_database_without_explicit_consent(): void
    {
        $this->assertStringContainsString('PRODUCTION_PROJECT='.ProductionGuard::PRODUCTION_PROJECT, $this->file('deploy/restore.sh'));

        $bash = (new Process(['bash', '--version']))->run() === 0;
        if (! $bash) {
            $this->markTestSkipped('bash introuvable.');
        }

        $backup = tempnam(sys_get_temp_dir(), 'backup');
        $key = tempnam(sys_get_temp_dir(), 'key');

        $process = new Process(['bash', base_path('deploy/restore.sh'), $backup], null, [
            'AGE_IDENTITY' => $key,
            'TARGET_DATABASE_URL' => 'postgresql://postgres.'.ProductionGuard::PRODUCTION_PROJECT.':x@aws-0-eu-central-1.pooler.supabase.com:5432/postgres',
        ]);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('La cible est la base de PRODUCTION', $process->getErrorOutput());
    }

    private function file(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
