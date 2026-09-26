<?php

namespace App\Services\Drafting;

use RuntimeException;

/**
 * No draft can be made: no API key configured, or Anthropic could not be
 * reached. Its own type so the controller can say so rather than return a 500.
 */
class DraftUnavailable extends RuntimeException {}
