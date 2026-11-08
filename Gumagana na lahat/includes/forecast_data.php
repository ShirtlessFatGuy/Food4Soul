<?php
/**
 * Data access for the sales forecast: turns rows of the `sales` table into the plain
 * monthly series that includes/forecasting.php (pure math) expects. No new tables: everything
 * is computed on request from the existing sales records.
 *
 * Rules applied to the history:
 *   - One value per calendar month: units sold (Quantity_Sold) or revenue (Total_Amount).
 *   - The current, still-running month is excluded: a partial month would look like a sales drop.
 *   - Months with no sales are kept as 0 (a real zero is information).
 *   - A product's series starts at its first sale month, so months before it was launched
 *     are not counted as "zero demand".
 *   - The company total series starts at the first sale month of any product.
 */
require_once __DIR__ . '/forecasting.php';

/** 'YYYY-MM' plus $n months (n may be negative). */
function fc_add_month(string $ym, int $n): string
{
    [$y, $m] = array_map('intval', explode('-', $ym));
    $idx = $y * 12 + ($m - 1) + $n;
    return sprintf('%04d-%02d', intdiv($idx, 12), $idx % 12 + 1);
}

/** 'YYYY-MM' -> 'Jan 2026'. */
function fc_label(string $ym): string
{
    return date('M Y', strtotime($ym . '-01'));
}

/** Whole months from $a to $b (b >= a). */
function fc_months_between(string $a, string $b): int
{
    [$ya, $ma] = array_map('intval', explode('-', $a));
    [$yb, $mb] = array_map('intval', explode('-', $b));
    return ($yb * 12 + $mb) - ($ya * 12 + $ma);
}

/**
 * Load every product's monthly series plus the company total.
 *
 * @param string $metric 'units' | 'revenue'
 * @param string|null $today override for tests (Y-m-d)
 * @return array{metric:string, last:?string, first:?string, total:array, products:array}
 *         products[pid] = ['id','name','category','start'=>'YYYY-MM','series'=>float[]]
 *         total         = ['start'=>'YYYY-MM','series'=>float[]]
 */
function forecast_load_series(PDO $pdo, string $metric = 'units', ?string $today = null): array
{
    $metric = $metric === 'revenue' ? 'revenue' : 'units';
    $col    = $metric === 'revenue' ? 'Total_Amount' : 'Quantity_Sold';
    $today  = $today ?? date('Y-m-d');
    $cutoff = substr($today, 0, 7) . '-01';            // first day of the running month (excluded)
    $last   = fc_add_month(substr($today, 0, 7), -1);   // last complete month

    // Daily totals per product; bucketed into months in PHP so the query stays portable.
    $stmt = $pdo->prepare("SELECT Product_ID, Sale_Date, SUM($col) AS v
                             FROM sales WHERE Sale_Date < ? GROUP BY Product_ID, Sale_Date");
    $stmt->execute([$cutoff]);

    $byProduct = [];
    $byMonthAll = [];
    $first = null;
    foreach ($stmt as $r) {
        $ym = substr((string)$r['Sale_Date'], 0, 7);
        $v  = (float)$r['v'];
        $byProduct[$r['Product_ID']][$ym] = ($byProduct[$r['Product_ID']][$ym] ?? 0.0) + $v;
        $byMonthAll[$ym] = ($byMonthAll[$ym] ?? 0.0) + $v;
        if ($first === null || $ym < $first) $first = $ym;
    }

    $fill = static function (array $byMonth, string $start, string $end): array {
        $n = fc_months_between($start, $end) + 1;
        $s = [];
        for ($i = 0; $i < $n; $i++) $s[] = $byMonth[fc_add_month($start, $i)] ?? 0.0;
        return $s;
    };

    $out = ['metric' => $metric, 'first' => $first, 'last' => $first ? $last : null,
            'total' => ['start' => $first, 'series' => []], 'products' => []];
    if ($first !== null && $first <= $last) {
        $out['total']['series'] = $fill($byMonthAll, $first, $last);
    } else {
        $out['first'] = $out['last'] = null;
    }

    $products = $pdo->query("SELECT Product_ID, Product_Name, Category FROM products ORDER BY Product_Name")->fetchAll();
    foreach ($products as $p) {
        $pid = $p['Product_ID'];
        $series = []; $start = null;
        if (!empty($byProduct[$pid]) && $out['last'] !== null) {
            $start  = min(array_keys($byProduct[$pid]));
            $series = $fill($byProduct[$pid], $start, $last);
        }
        $out['products'][$pid] = ['id' => $pid, 'name' => $p['Product_Name'], 'category' => $p['Category'],
                                  'start' => $start, 'series' => $series];
    }
    return $out;
}

/**
 * Run the validation-and-selection pipeline on one loaded series and attach month labels.
 * Returns Forecaster::forecastSeries() output plus 'hist_months' and 'fc_months' (YYYY-MM).
 */
function forecast_run_series(array $item, int $horizon, int $holdout, string $selectMetric): array
{
    $series = $item['series'];
    if (!$series || $item['start'] === null) {
        return ['status' => 'nodata', 'n' => 0, 'selected' => null, 'forecast' => [], 'candidates' => [],
                'warnings' => ['No sales recorded for this item yet.'], 'hist_months' => [], 'fc_months' => []];
    }
    $res = Forecaster::forecastSeries($series, $horizon, $holdout, $selectMetric);
    $n = count($series);
    $res['hist_months'] = [];
    for ($i = 0; $i < $n; $i++) $res['hist_months'][] = fc_add_month($item['start'], $i);
    $lastHist = fc_add_month($item['start'], $n - 1);
    $res['fc_months'] = [];
    for ($i = 1; $i <= count($res['forecast']); $i++) $res['fc_months'][] = fc_add_month($lastHist, $i);
    return $res;
}

/** Sum of the forecast in 12-month blocks: [year1, year2, year3]. */
function forecast_year_totals(array $fc): array
{
    $t = [];
    foreach (array_chunk($fc, 12) as $c) $t[] = array_sum($c);
    return $t;
}
