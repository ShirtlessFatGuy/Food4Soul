<?php
/**
 * Food4Soul sales forecasting engine: classical time-series methods only
 * (no machine learning, no AI), consistent with the "no AI / ML" limitation in Chapter 1.
 *
 * Method implemented (and nothing else):
 *   Holt-Winters triple exponential smoothing, additive and multiplicative seasonality.
 *   Level + trend + 12-month seasonality. Smoothing constants (alpha, beta, gamma) are found
 *   by minimising the in-sample sum of squared one-step-ahead errors (grid search + local
 *   refinement: plain numerical optimisation, nothing is "learned").
 *
 * Validation and selection (per series):
 *   Hold out the last H months (default 3), fit each eligible variant on the rest, forecast the
 *   hidden months and score them with MAE / RMSE / MAPE. The method with the lowest error on
 *   the holdout wins, is re-fitted on ALL the data, and produces the long-range forecast.
 *
 * This file is pure math: no database, no sessions, no output. Series are plain
 * zero-indexed arrays of non-negative numbers, one value per month, oldest first.
 */
final class Forecaster
{
    public const SEASON = 12;               // months per year
    public const M_HW_ADD  = 'Holt-Winters (additive)';
    public const M_HW_MULT = 'Holt-Winters (multiplicative)';

    /** Z for an (approximate) 80% prediction interval. */
    private const Z80 = 1.2816;

    // ------------------------------------------------------------------ public API

    /**
     * Backtest every eligible method, pick the best, refit it on the full series, forecast.
     *
     * @param float[] $y        monthly history, oldest first
     * @param int     $horizon  months to forecast (36 = three years)
     * @param int     $holdout  months hidden for the backtest (2-3 recommended)
     * @param string  $metric   'MAE' (default) or 'MAPE'. MAPE is undefined when the holdout
     *                          contains zero-sales months, so MAE is used instead in that case.
     * @param float   $phi      trend damping for Holt-Winters. 1.0 = classic undamped
     *                          Holt-Winters (default). Values like 0.95-0.99 flatten the trend
     *                          on long horizons.
     * @return array see the keys built below
     */
    public static function forecastSeries(array $y, int $horizon = 36, int $holdout = 3,
                                          string $metric = 'MAE', float $phi = 1.0): array
    {
        $y = array_map('floatval', array_values($y));
        $n = count($y);
        $horizon = max(1, $horizon);
        $holdout = max(1, $holdout);
        $metric  = strtoupper($metric) === 'MAPE' ? 'MAPE' : 'MAE';

        $out = [
            'status'      => 'ok',
            'n'           => $n,
            'horizon'     => $horizon,
            'holdout'     => $holdout,
            'candidates'  => [],
            'selected'    => null,
            'metric_used' => null,
            'params'      => null,
            'forecast'    => [],
            'lower'       => null,
            'upper'       => null,
            'seasonal'    => null,   // seasonal factors for forecast steps 1..12 (HW winner only)
            'seasonal_type' => null,
            'actual_holdout' => [],
            'warnings'    => [],
        ];

        $nTrain = $n - $holdout;
        if ($nTrain < 6) {
            $out['status'] = 'insufficient';
            $out['warnings'][] = "Only $n month(s) of history; need at least " . ($holdout + 6) . ' to backtest.';
            return $out;
        }

        $train  = array_slice($y, 0, $nTrain);
        $actual = array_slice($y, $nTrain);
        $out['actual_holdout'] = $actual;

        // ---- 1. Backtest every method on the holdout ---------------------------------
        $fitters = [
            self::M_HW_ADD  => static fn(array $s, int $h) => self::holtWinters($s, 'additive', $h, $phi),
            self::M_HW_MULT => static fn(array $s, int $h) => self::holtWinters($s, 'multiplicative', $h, $phi),
        ];

        foreach ($fitters as $name => $fit) {
            $res = $fit($train, $holdout);
            if (isset($res['error'])) {
                $out['candidates'][$name] = ['eligible' => false, 'reason' => $res['error']];
                continue;
            }
            $pred = array_map(static fn($v) => max(0.0, $v), $res['forecast']);
            $out['candidates'][$name] = [
                'eligible' => true,
                'params'   => $res['params'],
                'predicted_holdout' => $pred,
            ] + self::errorMetrics($actual, $pred);
        }

        $eligible = array_filter($out['candidates'], static fn($c) => $c['eligible']);
        if (!$eligible) {
            $out['status'] = 'insufficient';
            $out['warnings'][] = 'Holt-Winters could not be fitted: it needs at least 24 months of history (and, for the multiplicative form, no zero-sales months).';
            return $out;
        }

        // ---- 2. Pick the winner: lowest error on the hidden months -------------------
        $useMetric = $metric;
        if ($metric === 'MAPE') {
            foreach ($eligible as $c) {
                if ($c['mape'] === null) { $useMetric = 'MAE'; break; }
            }
            if ($useMetric !== $metric) {
                $out['warnings'][] = 'MAPE is undefined because the holdout has zero-sales months; selected on MAE instead.';
            }
        }
        $key = $useMetric === 'MAPE' ? 'mape' : 'mae';
        $best = null; $bestVal = INF;
        foreach ($eligible as $name => $c) {          // ties keep the first listed method
            if ($c[$key] < $bestVal - 1e-12) { $bestVal = $c[$key]; $best = $name; }
        }
        $out['selected']    = $best;
        $out['metric_used'] = $useMetric;
        $out['candidates'][$best]['selected'] = true;

        // ---- 3. Refit the winner on ALL data and forecast ----------------------------
        $final = $fitters[$best]($y, $horizon);
        if (isset($final['error'])) {
            // Can happen only if the full series is somehow ineligible although the training part was fine.
            $out['status'] = 'insufficient';
            $out['warnings'][] = 'Refit on the full history failed: ' . $final['error'];
            return $out;
        }

        $clamped = false;
        $fc = [];
        foreach ($final['forecast'] as $v) {
            if ($v < 0) { $clamped = true; $v = 0.0; }
            $fc[] = $v;
        }
        if ($clamped) $out['warnings'][] = 'Some forecast months went below zero and were set to 0.';

        $out['params']   = $final['params'];
        $out['forecast'] = $fc;
        if (isset($final['sigma'])) {
            $lo = $hi = [];
            foreach ($fc as $i => $v) {
                $w = self::Z80 * $final['sigma'] * sqrt($i + 1);
                $lo[] = max(0.0, $v - $w);
                $hi[] = $v + $w;
            }
            $out['lower'] = $lo;
            $out['upper'] = $hi;
        }
        if (isset($final['seasonal'])) {
            $out['seasonal']      = $final['seasonal'];
            $out['seasonal_type'] = $final['seasonal_type'];
        }

        // ---- 4. Honest caveats for the defense panel ---------------------------------
        if ($holdout < 3) {
            $out['warnings'][] = "Model chosen on only $holdout holdout month(s); treat the ranking as indicative.";
        }
        if ($n < 3 * self::SEASON) {
            $out['warnings'][] = "Only $n months of history for a 12-month seasonal model (3 full years recommended).";
        }
        if ($horizon > $n) {
            $out['warnings'][] = "Forecast horizon ($horizon months) is longer than the history ($n months); the far end is a projection, not a prediction.";
        }
        return $out;
    }

    // ------------------------------------------------------------------ Holt-Winters

    /**
     * Holt-Winters triple exponential smoothing with 12-month seasonality.
     *
     * @param string $mode 'additive' or 'multiplicative'
     * @return array ['forecast'=>[..h], 'params'=>[alpha,beta,gamma,phi], 'sigma'=>float, 'sse'=>float,
     *                'seasonal'=>[12 factors for steps 1..12], 'seasonal_type'=>..] or ['error'=>string]
     */
    public static function holtWinters(array $y, string $mode, int $h, float $phi = 1.0): array
    {
        $m = self::SEASON;
        $n = count($y);
        $mult = $mode === 'multiplicative';

        if ($n < 2 * $m) {
            return ['error' => "needs at least 2 full years ($m x 2 = " . (2 * $m) . " months) of history, has $n"];
        }
        if ($mult && min($y) <= 0) {
            return ['error' => 'series has zero-sales months; multiplicative seasonality needs strictly positive data'];
        }
        $init = self::hwInit($y, $m, $mult);
        if ($init === null) {
            return ['error' => 'could not initialise the level/trend/seasonal components'];
        }

        // Coarse grid, then a finer local search around the best cell.
        $grid = [
            'a' => [0.05, 0.15, 0.25, 0.35, 0.45, 0.55, 0.65, 0.75, 0.85, 0.95],
            'b' => [0.0, 0.02, 0.05, 0.1, 0.2, 0.3, 0.5],
            'g' => [0.05, 0.15, 0.25, 0.35, 0.45, 0.55, 0.65, 0.75, 0.85, 0.95],
        ];
        $best = self::hwSearch($y, $m, $mult, $phi, $init, $grid);
        if ($best === null) {
            return ['error' => 'optimisation failed (numerically unstable series)'];
        }
        $refine = static function (float $c, float $step, int $k, float $min = 0.0): array {
            $v = [];
            for ($i = -$k; $i <= $k; $i++) {
                $x = round($c + $i * $step, 4);
                if ($x >= $min && $x <= 1.0) $v[] = $x;
            }
            return $v;
        };
        $fine = self::hwSearch($y, $m, $mult, $phi, $init, [
            'a' => $refine($best['a'], 0.025, 4, 0.01),
            'b' => $refine($best['b'], 0.01, 4),
            'g' => $refine($best['g'], 0.025, 4, 0.01),
        ]);
        if ($fine !== null && $fine['sse'] < $best['sse']) $best = $fine;

        $run = self::hwRun($y, $m, $mult, $best['a'], $best['b'], $best['g'], $phi, $init, true);
        if ($run === null) {
            return ['error' => 'optimisation failed (numerically unstable series)'];
        }

        // Forecast h steps ahead from the final state.
        [$l, $b, $s] = [$run['l'], $run['b'], $run['s']];
        $fc = [];
        $tsum = 0.0; $pj = 1.0;
        for ($k = 1; $k <= $h; $k++) {
            $pj *= $phi;
            $tsum += $pj * $b;
            $si = $s[($n + $k - 1) % $m];
            $fc[] = $mult ? ($l + $tsum) * $si : $l + $tsum + $si;
        }
        $seasonal = [];
        for ($k = 1; $k <= $m; $k++) $seasonal[] = $s[($n + $k - 1) % $m];

        return [
            'forecast' => $fc,
            'params'   => ['alpha' => $best['a'], 'beta' => $best['b'], 'gamma' => $best['g'], 'phi' => $phi],
            'sigma'    => $run['sigma'],
            'sse'      => $run['sse'],
            'seasonal' => $seasonal,
            'seasonal_type' => $mult ? 'multiplicative' : 'additive',
        ];
    }

    /** Initial level / trend / seasonal state from the first two full seasons. */
    private static function hwInit(array $y, int $m, bool $mult): ?array
    {
        $m1 = array_sum(array_slice($y, 0, $m)) / $m;
        $m2 = array_sum(array_slice($y, $m, $m)) / $m;
        $b0 = ($m2 - $m1) / $m;
        $l0 = $m1 - $b0 * ($m + 1) / 2;            // level at time -1, so season-1 mean sits at its centre

        $s = array_fill(0, $m, 0.0);
        for ($i = 0; $i < $m; $i++) {
            $acc = 0.0;
            for ($k = 0; $k < 2; $k++) {
                $t  = $k * $m + $i;
                $tr = $l0 + $b0 * ($t + 1);
                if ($mult) {
                    if ($tr <= 0) return null;
                    $acc += $y[$t] / $tr;
                } else {
                    $acc += $y[$t] - $tr;
                }
            }
            $s[$i] = $acc / 2;
        }
        $avg = array_sum($s) / $m;
        for ($i = 0; $i < $m; $i++) {              // normalise: mult -> mean 1, add -> sum 0
            if ($mult) {
                if ($avg <= 0) return null;
                $s[$i] /= $avg;
            } else {
                $s[$i] -= $avg;
            }
        }
        if ($mult && min($s) <= 0) return null;
        return ['l' => $l0, 'b' => $b0, 's' => $s];
    }

    /** Exhaustive search of the (alpha, beta, gamma) grid; returns the cell with the lowest SSE. */
    private static function hwSearch(array $y, int $m, bool $mult, float $phi, array $init, array $grid): ?array
    {
        $best = null;
        foreach ($grid['a'] as $a) {
            foreach ($grid['b'] as $b) {
                foreach ($grid['g'] as $g) {
                    $r = self::hwRun($y, $m, $mult, $a, $b, $g, $phi, $init, false);
                    if ($r === null) continue;
                    if ($best === null || $r['sse'] < $best['sse']) {
                        $best = ['a' => $a, 'b' => $b, 'g' => $g, 'sse' => $r['sse']];
                    }
                }
            }
        }
        return $best;
    }

    /**
     * One pass of the Holt-Winters recursions over the series.
     * Returns the sum of squared one-step errors and the final state, or null if it blew up.
     */
    private static function hwRun(array $y, int $m, bool $mult, float $a, float $bb, float $g,
                                  float $phi, array $init, bool $wantSigma): ?array
    {
        $l = $init['l']; $b = $init['b']; $s = $init['s'];
        $n = count($y);
        $sse = 0.0;
        $sseLate = 0.0; $cntLate = 0;

        for ($t = 0; $t < $n; $t++) {
            $i    = $t % $m;
            $base = $l + $phi * $b;                          // level + damped trend = one-step "deseasonalised" forecast
            if ($mult) {
                if ($s[$i] <= 0) return null;
                $f = $base * $s[$i];
            } else {
                $f = $base + $s[$i];
            }
            $e = $y[$t] - $f;
            $sse += $e * $e;
            if ($t >= $m) { $sseLate += $e * $e; $cntLate++; }

            if ($mult) {
                $lNew = $a * ($y[$t] / $s[$i]) + (1 - $a) * $base;
                $sNew = $base != 0.0 ? $g * ($y[$t] / $base) + (1 - $g) * $s[$i] : $s[$i];
            } else {
                $lNew = $a * ($y[$t] - $s[$i]) + (1 - $a) * $base;
                $sNew = $g * ($y[$t] - $base) + (1 - $g) * $s[$i];
            }
            $b = $bb * ($lNew - $l) + (1 - $bb) * $phi * $b;
            $l = $lNew;
            $s[$i] = $sNew;

            if (!is_finite($l) || !is_finite($b) || !is_finite($sse)) return null;
        }
        $res = ['sse' => $sse, 'l' => $l, 'b' => $b, 's' => $s];
        if ($wantSigma) {
            // residual std-dev of one-step errors, skipping the first season (used for initialisation)
            $res['sigma'] = $cntLate > 0 ? sqrt($sseLate / $cntLate) : sqrt($sse / max(1, $n));
        }
        return $res;
    }

    // ------------------------------------------------------------------ error metrics

    /** MAE, RMSE, MAPE (non-zero actuals only), bias. */
    public static function errorMetrics(array $actual, array $pred): array
    {
        $n = count($actual);
        $abs = $sq = $bias = 0.0;
        $pct = 0.0; $pctN = 0;
        for ($i = 0; $i < $n; $i++) {
            $e = $actual[$i] - $pred[$i];
            $abs += abs($e);
            $sq  += $e * $e;
            $bias += $pred[$i] - $actual[$i];
            if ($actual[$i] != 0.0) { $pct += abs($e) / abs($actual[$i]); $pctN++; }
        }
        return [
            'mae'  => $n ? $abs / $n : null,
            'rmse' => $n ? sqrt($sq / $n) : null,
            'mape' => $pctN > 0 ? 100.0 * $pct / $pctN : null,
            'bias' => $n ? $bias / $n : null,
        ];
    }
}
