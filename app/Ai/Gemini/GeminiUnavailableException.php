<?php

namespace App\Ai\Gemini;

use RuntimeException;

/** Лимиты исчерпаны на всех ключах и моделях — повтор имеет смысл после паузы. */
class GeminiUnavailableException extends RuntimeException {}
