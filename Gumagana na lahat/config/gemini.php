<?php
/**
 * Gemini settings for the plain-language forecast summary (admin/forecast.php).
 *
 * Gemini ONLY rewrites the finished Holt-Winters results as a short, simple explanation.
 * It never produces or changes a forecast number: every figure it is given is computed by
 * includes/forecasting.php first.
 *
 * Put your key in ONE of these places (never commit a real key to a public repository):
 *   1. Environment variable GEMINI_API_KEY  (recommended; on Hostinger set it in hPanel or .htaccess:
 *          SetEnv GEMINI_API_KEY your-key-here )
 *   2. Paste it below in 'api_key' (fine for a local XAMPP demo only).
 *
 * Get a key at https://aistudio.google.com/apikey
 */
return [
    'api_key'       => getenv('GEMINI_API_KEY') ?: 'AQ.Ab8RN6JsolSgN98-fgHgl9LsUTO5xsMUc2MYayaCFQFkTdIoWQ',
    // 'gemini-flash-latest' always points at Google's current Flash model. If you prefer to pin one,
    // use a model name from https://ai.google.dev/gemini-api/docs/models (e.g. 'gemini-2.5-flash').
    'model'         => 'gemini-3.5-flash-lite',
    'timeout'       => 25,        // seconds to wait for Gemini before showing the fallback message
    'cache_hours'   => 24,        // identical data + settings reuse the saved summary (saves quota)
    'language'      => 'English', // e.g. 'English', 'Filipino (Taglish)'
];