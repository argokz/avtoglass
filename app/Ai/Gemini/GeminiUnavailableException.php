<?php

namespace App\Ai\Gemini;

use App\Ai\AiProviderException;

/** Лимиты исчерпаны на всех ключах и моделях — повтор имеет смысл после паузы. */
class GeminiUnavailableException extends AiProviderException {}
