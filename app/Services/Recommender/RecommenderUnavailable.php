<?php

namespace App\Services\Recommender;

use RuntimeException;

/**
 * The adviser cannot answer: no API key configured, or Anthropic could not be
 * reached. Its own type so the controller can tell that apart from a bug and
 * say something useful rather than returning a 500.
 */
class RecommenderUnavailable extends RuntimeException {}
