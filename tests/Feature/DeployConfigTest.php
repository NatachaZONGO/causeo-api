<?php

namespace Tests\Feature;

use App\Http\Controllers\API\V1\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Le VPS (deploy/) doit se comporter comme Render (docker-entrypoint.sh), et ses
 * réglages nginx / php-fpm doivent laisser passer ce que l'application accepte.
 */
class DeployConfigTest extends TestCase
{
    public function test_vps_worker_uses_the_same_options_as_render(): void
    {
        preg_match('/php artisan (queue:work [^|\n]+?)\s*(\|\||$)/m', $this->file('docker-entrypoint.sh'), $render);
        preg_match('/artisan (queue:work .+)$/m', $this->file('deploy/supervisor/causeo-worker.conf'), $vps);

        $this->assertSame(trim($render[1]), trim($vps[1]));

        // Supervisor laisse le job en cours finir avant d'arrêter le worker.
        preg_match('/--timeout=(\d+)/', $vps[1], $timeout);
        preg_match('/^stopwaitsecs=(\d+)$/m', $this->file('deploy/supervisor/causeo-worker.conf'), $stopWait);
        $this->assertGreaterThan((int) $timeout[1], (int) $stopWait[1]);
    }

    public function test_deploy_runs_the_same_steps_as_the_render_entrypoint(): void
    {
        $entrypoint = $this->file('docker-entrypoint.sh');
        $deploy = $this->file('deploy/deploy.sh');

        preg_match_all('/artisan (migrate --force|db:seed --class=\w+ --force)/', $entrypoint, $render);
        $this->assertNotEmpty($render[1]);

        foreach ($render[1] as $step) {
            $this->assertStringContainsString("artisan {$step}", $deploy);
        }

        foreach (['config:cache', 'route:cache', 'queue:restart', 'composer install --no-dev'] as $step) {
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
        preg_match('/upload_max_filesize\] = (\d+)M/', $this->file('deploy/php/causeo-pool.conf'), $upload);
        preg_match('/post_max_size\] = (\d+)M/', $this->file('deploy/php/causeo-pool.conf'), $post);

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

        // Activé seulement à la bascule (causeo-scheduler on), pour ne pas tourner en double avec Render.
        $this->assertStringNotContainsString('/etc/cron.d/causeo-scheduler', $setup);
        $this->assertStringContainsString('/etc/cron.d/causeo-scheduler', $this->file('deploy/scheduler.sh'));
        $this->assertStringContainsString('${RUN_SCHEDULER:-true}', $this->file('docker-entrypoint.sh'));
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
