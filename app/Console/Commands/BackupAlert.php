<?php

namespace App\Console\Commands;

use App\Mail\OwnerNotificationMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Alerte d'échec de la sauvegarde de la base (deploy/backup.sh) : e-mail aux admins
 * Causeo et aux adresses de --email. Envoi immédiat (pas de file), et la liste des admins
 * est facultative : la base peut être justement ce qui est en panne.
 */
class BackupAlert extends Command
{
    protected $signature = 'causeo:backup-alert
                            {reason : Ce qui a échoué}
                            {--email=* : Adresses à prévenir en plus des admins (BACKUP_ALERT_EMAILS)}';

    protected $description = 'Prévenir par e-mail qu\'une sauvegarde de la base a échoué (appelé par deploy/backup.sh)';

    public function handle(): int
    {
        $recipients = collect($this->option('email'))
            ->flatMap(fn (string $value) => explode(',', $value))
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        try {
            $recipients = $recipients->merge(User::query()->where('is_admin', true)->whereNotNull('email')->pluck('email'));
        } catch (Throwable $e) {
            $this->warn('Admins introuvables (base injoignable ?) : seules les adresses de --email sont prévenues.');
        }

        $recipients = $recipients->unique()->values();

        if ($recipients->isEmpty()) {
            $this->error('Aucun destinataire : renseignez BACKUP_ALERT_EMAILS dans /etc/causeo-backup.env.');

            return self::FAILURE;
        }

        $mail = new OwnerNotificationMail(
            'Échec de la sauvegarde de la base Causeo',
            [
                'La sauvegarde quotidienne de la base de production a échoué le '.now()->timezone('Africa/Ouagadougou')->format('d/m/Y à H:i').' (heure de Ouagadougou).',
                'Raison : '.mb_substr((string) $this->argument('reason'), 0, 500),
                'Sur le VPS : sudo tail -n 50 /var/log/causeo-backup.log, puis relancer sudo causeo-backup (voir SAUVEGARDES.md).',
            ],
            'Ouvrir l\'administration',
            rtrim((string) config('app.frontend_url'), '/').'/admin',
        );

        $sent = 0;
        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send($mail);
                $sent++;
            } catch (Throwable $e) {
                $this->error("Envoi impossible à {$email} : ".$e::class);
            }
        }

        $this->info("Alerte envoyée à {$sent} destinataire(s).");

        return $sent > 0 ? self::SUCCESS : self::FAILURE;
    }
}
