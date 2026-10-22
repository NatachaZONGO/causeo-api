<?php

namespace Tests\Feature;

use App\Http\Controllers\API\V1\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Les réglages du VPS (deploy/) : worker, déploiement, et nginx / php-fpm qui doivent
 * laisser passer ce que l'application accepte.
 */
class DeployConfigTest extends TestCase
{
    public function test_vps_worker_retries_and_finishes_the_current_job(): void
    {
        $conf = $this->file('deploy/supervisor/causeo-worker.conf');

        preg_match('/artisan (queue:work .+)$/m', $conf, $worker);
        $this->assertStringContainsString('--tries=3', $worker[1]);

        // Supervisor laisse le job en cours finir avant d'arrêter le worker.
        preg_match('/--timeout=(\d+)/', $worker[1], $timeout);
        preg_match('/^stopwaitsecs=(\d+)$/m', $conf, $stopWait);
        $this->assertGreaterThan((int) $timeout[1], (int) $stopWait[1]);
    }

    public function test_deploy_migrates_seeds_and_rebuilds_the_caches(): void
    {
        $deploy = $this->file('deploy/deploy.sh');

        foreach ([
            'artisan migrate --force',
            'artisan db:seed --class=BusinessTemplateSeeder --force',
            'artisan db:seed --class=PlanSeeder --force',
            'config:cache', 'route:cache', 'queue:restart', 'composer install --no-dev',
        ] as $step) {
            $this->assertStringContainsString($step, $deploy);
        }
    }

    public function test_php_fpm_lets_the_webhook_processing_finish(): void
    {
        $pool = $this->file('deploy/php/causeo-pool.conf');

        preg_match('/^request_terminate_timeout = (\d+)s$/m', $pool, $terminate);
        $this->assertGreaterThan(WebhookController::BACKGROUND_TIME_LIMIT, (int) $terminate[1]);

        // php_value (et non php_admin_value) : set_time_limit() doit pouvoir la prolonger.
        $this->assertMatchesRegularExpression('/^php_value\[max_execution_time\] = \d+$/m', $pool);
        $this->assertStringNotContainsString('php_admin_value[max_execution_time]', $pool);
    }

    public function test_nginx_and_php_accept_the_largest_upload(): void
    {
        // Plus gros fichier accepté par l'application (règle « max: » en Ko).
        $largest = 0;
        foreach (glob(app_path('Http/Controllers/API/V1/{,*/}*.php'), GLOB_BRACE) as $controller) {
            preg_match_all("/'file'[^\\]]*'max:(\\d+)'/", file_get_contents($controller), $rules);
            $largest = max([$largest, ...array_map('intval', $rules[1])]);
        }
        $this->assertGreaterThanOrEqual(16384, $largest);

        preg_match('/client_max_body_size (\d+)M;/', $this->file('deploy/nginx/api.conf'), $nginx);
        // Limites PHP dans conf.d (99-causeo.ini) : le pool ne doit plus les redéfinir.
        $ini = $this->file('deploy/php/99-causeo.ini');
        preg_match('/^upload_max_filesize = (\d+)M$/m', $ini, $upload);
        preg_match('/^post_max_size = (\d+)M$/m', $ini, $post);
        $pool = $this->file('deploy/php/causeo-pool.conf');
        $this->assertDoesNotMatchRegularExpression('/^\s*php_(admin_)?value\[(upload_max_filesize|post_max_size)\]/m', $pool);

        $this->assertGreaterThanOrEqual($largest, (int) $nginx[1] * 1024);
        $this->assertGreaterThanOrEqual($largest, (int) $upload[1] * 1024);
        $this->assertGreaterThan((int) $upload[1], (int) $post[1]);
    }

    public function test_nginx_talks_to_the_causeo_php_fpm_pool(): void
    {
        preg_match('/^listen = (\S+)$/m', $this->file('deploy/php/causeo-pool.conf'), $listen);

        $this->assertStringContainsString("fastcgi_pass unix:{$listen[1]};", $this->file('deploy/nginx/api.conf'));
    }

    public function test_the_scheduler_is_not_enabled_by_setup(): void
    {
        $setup = $this->file('deploy/setup.sh');

        // Activé explicitement (causeo-scheduler on) : un second serveur sur la même base
        // ne doit pas envoyer les rappels et le rapport hebdomadaire en double.
        $this->assertStringNotContainsString('/etc/cron.d/causeo-scheduler', $setup);
        $this->assertStringContainsString('/etc/cron.d/causeo-scheduler', $this->file('deploy/scheduler.sh'));
    }

    public function test_unattended_upgrades_also_update_php_and_restart_it(): void
    {
        $setup = $this->file('deploy/setup.sh');

        // Le dépôt ondrej s'ajoute aux mises à jour de sécurité d'Ubuntu (50unattended-upgrades, intact).
        $upgrades = $this->file('deploy/apt/52causeo-unattended-upgrades');
        $this->assertMatchesRegularExpression('/Unattended-Upgrade::Origins-Pattern \{\s*"origin=LP-PPA-ondrej-php";\s*\};/', $upgrades);
        $this->assertStringContainsString('APT::Periodic::Unattended-Upgrade "1";', $upgrades);
        $this->assertStringContainsString('apt/52causeo-unattended-upgrades" /etc/apt/apt.conf.d/52causeo-unattended-upgrades', $setup);
        $this->assertMatchesRegularExpression('/apt-get install .*\\\\\n.*unattended-upgrades/', $setup);

        // Après dpkg, le script redémarre php-fpm et le worker si un paquet PHP a changé.
        $this->assertStringContainsString('DPkg::Post-Invoke', $this->file('deploy/apt/53causeo-php-restart'));
        $this->assertStringContainsString('/usr/local/sbin/causeo-php-restart', $this->file('deploy/apt/53causeo-php-restart'));
        $this->assertStringContainsString('apt/php-restart.sh" /usr/local/sbin/causeo-php-restart 755', $setup);
        $this->assertStringContainsString('apt/53causeo-php-restart" /etc/apt/apt.conf.d/53causeo-php-restart', $setup);

        $script = $this->file('deploy/apt/php-restart.sh');
        $this->assertStringContainsString('systemctl restart "php${PHP_VERSION}-fpm"', $script);
        preg_match('/^\[program:(\S+)\]$/m', $this->file('deploy/supervisor/causeo-worker.conf'), $worker);
        $this->assertStringContainsString("supervisorctl restart '{$worker[1]}:*'", $script);
        preg_match('/^PHP_VERSION=([\d.]+)$/m', $setup, $vps);
        $this->assertStringContainsString("PHP_VERSION={$vps[1]}\n", $script);
    }

    public function test_github_deploys_the_tested_commit_one_at_a_time(): void
    {
        $workflow = $this->file('.github/workflows/deploy.yml');

        // Le déploiement attend les tests, ne tourne jamais en double et vise le commit testé.
        $this->assertMatchesRegularExpression('/^  deploy:\n(?:    .*\n)*?    needs: tests$/m', $workflow);
        $this->assertMatchesRegularExpression('/concurrency:\s+group: deploy-production\s+cancel-in-progress: false/', $workflow);
        $this->assertStringContainsString('"$TARGET $GITHUB_SHA"', $workflow);
        $this->assertStringContainsString('StrictHostKeyChecking=yes', $workflow);

        // Les tests tournent avec le PHP installé sur le VPS.
        preg_match("/php-version: '([\\d.]+)'/", $workflow, $ci);
        preg_match('/^PHP_VERSION=([\d.]+)$/m', $this->file('deploy/setup.sh'), $vps);
        $this->assertSame($vps[1], $ci[1]);
        $this->assertStringContainsString("/usr/bin/php{$vps[1]} ", $this->file('deploy/supervisor/causeo-worker.conf'));

        // Aucun secret en clair : seulement des références aux secrets GitHub.
        $this->assertDoesNotMatchRegularExpression('/BEGIN [A-Z ]*PRIVATE KEY|ssh-ed25519 AAAA/', $workflow);

        // La clé de GitHub Actions ne peut lancer que deploy.sh, qui valide ses arguments.
        $this->assertStringContainsString('restrict,command="%s/deploy/deploy.sh"', $this->file('deploy/setup.sh'));
        $this->assertStringContainsString('SSH_ORIGINAL_COMMAND', $this->file('deploy/deploy.sh'));
        $this->assertStringContainsString('flock', $this->file('deploy/deploy.sh'));
    }

    public function test_forwarded_headers_are_trusted_only_from_a_local_proxy(): void
    {
        Route::get('/_test/request-info', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
        ]);
        $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '41.138.10.20'];

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('http://localhost/_test/request-info', $headers)
            ->assertExactJson(['secure' => true, 'ip' => '41.138.10.20']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->getJson('http://localhost/_test/request-info', $headers)
            ->assertExactJson(['secure' => false, 'ip' => '203.0.113.7']);
    }

    private function file(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
