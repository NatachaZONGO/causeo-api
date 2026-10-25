<?php

namespace Tests\Postgres;

use App\Support\Pgvector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Extension pgvector dans le schéma public, même quand un projet Supabase neuf l'a
 * installée dans « extensions » (voir database/sql/pgvector.sql).
 */
class PgvectorTest extends PostgresTestCase
{
    public function test_the_migrations_put_vector_in_public_and_running_again_changes_nothing(): void
    {
        $this->assertSame('public', $this->vectorSchema());
        $oid = DB::scalar("SELECT oid FROM pg_extension WHERE extname = 'vector'");

        Pgvector::install();

        $this->assertSame('public', $this->vectorSchema());
        $this->assertSame($oid, DB::scalar("SELECT oid FROM pg_extension WHERE extname = 'vector'"));
    }

    public function test_vector_installed_elsewhere_is_moved_to_public_and_columns_keep_working(): void
    {
        $chunk = $this->documentChunk();
        DB::statement('CREATE SCHEMA IF NOT EXISTS extensions');
        DB::statement('ALTER EXTENSION vector SET SCHEMA extensions');

        Pgvector::install();

        $this->assertSame('public', $this->vectorSchema());
        $this->assertEqualsWithDelta(1.0, (float) DB::scalar('SELECT 1 - (embedding <=> ?::vector) FROM document_chunks WHERE id = ?', [$this->embedding(), $chunk]), 1e-6);
    }

    public function test_vector_that_cannot_be_moved_while_columns_use_it_stops_with_a_clear_error(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS extensions');
        DB::statement('ALTER EXTENSION vector SET SCHEMA extensions');
        // Comme Supabase quand le rôle n'a pas le droit de déplacer l'extension.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION causeo_refuse_alter_extension() RETURNS event_trigger LANGUAGE plpgsql
                AS $$ BEGIN RAISE EXCEPTION 'must be owner of extension vector'; END $$;
            CREATE EVENT TRIGGER causeo_refuse_alter_extension ON ddl_command_start
                WHEN TAG IN ('ALTER EXTENSION') EXECUTE FUNCTION causeo_refuse_alter_extension();
            SQL);

        try {
            DB::transaction(fn () => Pgvector::install());
            $this->fail('Pgvector::install() aurait dû échouer.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('dans le schéma « extensions » au lieu de « public »', $e->getMessage());
        }

        // Rien n'est supprimé : les colonnes vector restent.
        $this->assertSame('extensions', $this->vectorSchema());
        $this->assertTrue((bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'document_chunks' AND column_name = 'embedding')"));
    }

    private function vectorSchema(): ?string
    {
        return DB::scalar("SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'vector'");
    }

    private function documentChunk(): string
    {
        $business = $this->business($this->user());
        $document = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $document, 'business_id' => $business, 'title' => 'Tarifs', 'file_path' => 'tarifs.txt',
            'file_type' => 'txt', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $chunk = (string) Str::uuid();
        DB::insert('INSERT INTO document_chunks (id, document_id, business_id, content, chunk_index, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?::vector, now(), now())', [
            $chunk, $document, $business, 'Robe wax : 12 500 F', $this->embedding(),
        ]);

        return $chunk;
    }

    private function embedding(): string
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = 1.0;

        return '['.implode(',', $vector).']';
    }
}
