<?php
/**
 * Plain-language forecast summary (Gemini) for the Sales Forecast page.
 *   GET  /api/forecast_summary.php?item=ALL&metric=units&horizon=36&select=MAE&holdout=3
 *        -> saved summary if the data is unchanged, otherwise asks Gemini.
 *   POST same parameters + csrf=<token> + regenerate=1
 *        -> forces a fresh summary (uses API quota, so it needs the CSRF token).
 * Admin only. The API key stays on the server; the browser only ever receives the finished text.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/forecast_data.php';
require_once __DIR__ . '/../includes/gemini.php';
require_role('Admin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$force = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or expired page. Reload and try again.']);
        exit;
    }
    $force = !empty($in['regenerate']);
}
// Release the session lock: a Gemini call can take several seconds and must not stall live updates.
session_write_close();
set_time_limit(60);

// ---- same whitelisting as admin/forecast.php ----------------------------------------------
$metric  = ($in['metric'] ?? 'units') === 'revenue' ? 'revenue' : 'units';
$select  = strtoupper((string)($in['select'] ?? 'MAE')) === 'MAPE' ? 'MAPE' : 'MAE';
$holdout = (int)($in['holdout'] ?? 3);
$holdout = in_array($holdout, [2, 3], true) ? $holdout : 3;
$horizon = (int)($in['horizon'] ?? 36);
$horizon = in_array($horizon, [12, 24, 36], true) ? $horizon : 36;
$item    = (string)($in['item'] ?? 'ALL');

if (!gemini_enabled()) {
    echo json_encode(['ok' => false, 'error' => 'no_key']);
    exit;
}

$data = forecast_load_series($pdo, $metric);
if ($item !== 'ALL' && !isset($data['products'][$item])) $item = 'ALL';

$runs = [];
if ($item === 'ALL') {
    $runs['ALL'] = forecast_run_series(['start' => $data['total']['start'], 'series' => $data['total']['series']],
                                        $horizon, $holdout, $select);
    foreach ($data['products'] as $pid => $p) $runs[$pid] = forecast_run_series($p, $horizon, $holdout, $select);
} else {
    $runs[$item] = forecast_run_series($data['products'][$item], $horizon, $holdout, $select);
}

$facts = forecast_ai_facts($data, $runs, $item, $metric, $horizon, $holdout, $select);
if (!$facts) {
    echo json_encode(['ok' => false, 'error' => 'nodata']);
    exit;
}

$r = gemini_summarize($facts, $force);
echo json_encode([
    'ok'           => $r['ok'],
    'summary'      => $r['summary'],
    'error'        => $r['error'],
    'cached'       => $r['cached'],
    'generated_at' => $r['generated_at'] ? date('M j, Y g:i A', $r['generated_at']) : null,
], JSON_UNESCAPED_UNICODE);
