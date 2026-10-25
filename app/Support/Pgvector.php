<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Extension pgvector dans le schéma public, où les migrations l'attendent : un projet
 * Supabase neuf l'installe dans « extensions », hors du search_path, et les colonnes
 * vector(1536) échouent alors (type "vector" does not exist). Créée, ou déplacée si elle
 * est ailleurs ; en production, déjà dans public, rien ne change. Voir database/sql/pgvector.sql.
 */
final class Pgvector
{
    public static function install(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/pgvector.sql')));
    }
}
