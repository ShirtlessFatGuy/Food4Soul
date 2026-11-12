<?php
/**
 * Gemini settings for the plain-language forecast summary (admin/forecast.php).
 *
 * Gemini ONLY rewrites the finished Holt-Winters results as a short, simple explanation.
 * It never produces or changes a forecast number: every figure it is given is computed by
 * includes/forecasting.php first.
 *
 * The API key is NOT stored here. Set GEMINI_API_KEY as an environment variable, or put it in
 * config/secrets.php (copy config/secrets.example.php). Blank key = the summary is simply switched off.
 * Get a key at https://aistudio.google.com/apikey
 */
require_once __DIR__ . '/env.php';

return [
    'api_key'       => secret('GEMINI_API_KEY'),
    // 'gemini-flash-latest' always points at Google's current Flash model. If you prefer to pin one,
    // use a model name from https://ai.google.dev/gemini-api/docs/models (e.g. 'gemini-2.5-flash').
    'model'         => 'gemini-3.5-flash-lite',
    'timeout'       => 25,        // seconds to wait for Gemini before showing the fallback message
    'cache_hours'   => 24,        // identical data + settings reuse the saved summary (saves quota)
    'language'      => 'English', // e.g. 'English', 'Filipino (Taglish)'
];
