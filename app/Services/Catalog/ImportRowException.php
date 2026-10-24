<?php

namespace App\Services\Catalog;

use RuntimeException;

/**
 * Ligne d'un import de catalogue refusée ; le message est montré au gérant.
 */
class ImportRowException extends RuntimeException {}
