-- pgvector dans le schéma public (ou celui de causeo.vector_schema, voir deploy/restore.sh),
-- où les migrations et les sauvegardes l'attendent : le type « vector » y est cherché sans
-- préfixe et le search_path du pooler Supabase se limite à public. Utilisé par
-- App\Support\Pgvector (migrations) et deploy/restore.sh.
--
-- - Absente : créée dans le schéma voulu.
-- - Déjà dans le schéma voulu (la production) : rien ne change.
-- - Ailleurs (un projet Supabase neuf l'installe dans « extensions ») : déplacée. Si Supabase
--   refuse le déplacement, elle est supprimée puis recréée, ce que PostgreSQL n'accepte que
--   si aucune colonne ne l'utilise encore (DROP sans CASCADE) : sinon, erreur explicite.
DO $pgvector$
DECLARE
    target text := coalesce(nullif(current_setting('causeo.vector_schema', true), ''), 'public');
    installed text;
BEGIN
    SELECT n.nspname INTO installed
    FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace
    WHERE e.extname = 'vector';

    IF installed IS NULL THEN
        EXECUTE format('CREATE EXTENSION vector WITH SCHEMA %I', target);
    ELSIF installed <> target THEN
        BEGIN
            EXECUTE format('ALTER EXTENSION vector SET SCHEMA %I', target);
        EXCEPTION WHEN OTHERS THEN
            BEGIN
                DROP EXTENSION vector;
                EXECUTE format('CREATE EXTENSION vector WITH SCHEMA %I', target);
            EXCEPTION WHEN OTHERS THEN
                RAISE EXCEPTION 'L''extension vector est dans le schéma « % » au lieu de « % » et ne peut pas y être déplacée (%). Voir DEVELOPPEMENT.md, « pgvector ».', installed, target, SQLERRM;
            END;
        END;
    END IF;
END
$pgvector$;
