<?php

/*
 * AI-помощник на Gemini.
 *
 * Переключатель — в CRM (Настройки → AI-помощник), здесь только то, чему
 * не место в базе: ключи и запасной список моделей. CRM может переопределить
 * список моделей и паузу после лимита — её значения важнее.
 */
return [
    /*
     * Ключи AI Studio. Второй — резерв: ротатор перебирает пары
     * «ключ + модель» по кругу, пока не найдёт живую.
     */
    'keys' => array_values(array_filter([
        env('GEMINI_API_KEY'),
        env('GEMINI_API_KEY_SECOND'),
    ])),

    /*
     * Бесплатные Flash / Flash-Lite из семейства Gemini 3, по убыванию
     * качества. 2.0 сняты 1 июня 2026, 2.5 новым ключам часто уже не отдают.
     * 429 или 404 (модель снята) — связка отдыхает, круг идёт дальше.
     * Пустой GEMINI_MODELS — этот список. Свой можно задать в CRM.
     *
     * Актуальные id: https://ai.google.dev/gemini-api/docs/models
     */
    'models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_MODELS', ''))))) ?: [
        'gemini-3.8-flash',
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
    ],

    /* Сколько минут модель отдыхает после 429. */
    'cooldown_minutes' => (int) env('GEMINI_COOLDOWN_MINUTES', 60),

    'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',

    /* Сообщений в минуту от одной сессии — чтобы чат не крутили скриптом. */
    'rate_limit_per_minute' => 20,
];
