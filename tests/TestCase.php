<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Texte complet du prompt système d'une requête envoyée à Claude (envoyé en
     * plusieurs blocs, le premier mis en cache).
     *
     * @param  array<string, mixed>  $request
     */
    protected function systemPrompt(array $request): string
    {
        return collect($request['system'])->pluck('text')->implode("\n\n");
    }
}
