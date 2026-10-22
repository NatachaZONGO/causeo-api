<?php

namespace Tests\Feature;

use App\Models\Conversation;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Garde-fous pour ce que SQLite (tests) accepte et PostgreSQL (production) refuse.
 * Les vraies vérifications sur PostgreSQL : tests/Postgres (job CI dédié).
 */
class DatabasePortabilityTest extends TestCase
{
    public function test_no_of_many_relation_in_the_code(): void
    {
        // latestOfMany / oldestOfMany / ofMany départagent avec MAX(id) : impossible sur un uuid sous PostgreSQL.
        $offenders = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if (preg_match('/\b(latestOfMany|oldestOfMany|ofMany)\s*\(/', $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Remplacer ces relations par une sous-requête (voir Conversation::latestMessage).');
    }

    public function test_latest_message_query_never_aggregates_ids(): void
    {
        $sql = strtolower((new Conversation)->latestMessage()->toSql());

        $this->assertStringNotContainsString('max(', $sql);
        $this->assertStringContainsString('order by "latest"."created_at" desc, "latest"."id" desc limit 1', $sql);
    }
}
