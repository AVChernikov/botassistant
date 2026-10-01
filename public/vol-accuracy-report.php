<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\Exception\ApiException;
use Lighter\TechnicalAnalysis;

$marketId = filter_var($_GET['market_id'] ?? 120, FILTER_VALIDATE_INT);
$marketId = $marketId === false ? 120 : $marketId;
$marketLabels = [
    1 => 'BTC',
    120 => 'LIT',
];
$marketSymbol = $marketLabels[$marketId] ?? ('#' . $marketId);
$resolutions = ['1d', '12h', '4h', '1h', '30m', '15m'];
$allowedCandleCounts = [200, 400, 600];
$candleCount = filter_var($_GET['candles'] ?? 400, FILTER_VALIDATE_INT);
if ($candleCount === false || !in_array($candleCount, $allowedCandleCounts, true)) {
    $candleCount = 400;
}
$volMetric = (string) ($_GET['vol'] ?? 'ATR(14) pct');
if (!in_array($volMetric, TechnicalAnalysis::VOLATILITY_METHODS, true)) {
    $volMetric = 'ATR(14) pct';
}
$horizon = 1;
$error = null;
$report = null;
$loaded = [];
$frames = [];

try {
    $client = Client::mainnet(60);
    $details = $client->orderBookDetails($marketId);
    $marketRow = ($details['order_book_details'] ?? [])[0] ?? null;
    if (is_array($marketRow) && !empty($marketRow['symbol'])) {
        $marketSymbol = (string) $marketRow['symbol'];
    }
    $requests = [];
    foreach ($resolutions as $resolution) {
        foreach ($client->candlesHistoryRequests($marketId, $resolution, $candleCount) as $pageKey => $req) {
            $requests[$resolution . '__' . $pageKey] = $req;
        }
    }
    $responses = $client->getMany($requests);
    foreach ($resolutions as $resolution) {
        $pages = [];
        foreach ($responses as $key => $payload) {
            if (str_starts_with((string) $key, $resolution . '__')) {
                $pages[] = $payload;
            }
        }
        $items = Client::mergeCandlePages($pages, $candleCount);
        $frames[$resolution] = $items;
        $loaded[$resolution] = count($items);
    }
    $report = TechnicalAnalysis::runVolAccuracyReport($frames, $horizon, $volMetric);
} catch (ApiException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function pct(?float $v, int $digits = 1): string
{
    if ($v === null) {
        return '—';
    }

    return number_format($v * 100, $digits) . '%';
}

function num(?float $v, int $digits = 3): string
{
    if ($v === null) {
        return '—';
    }

    return number_format($v, $digits);
}

function corrClass(?float $v): string
{
    if ($v === null) {
        return '';
    }
    if ($v >= 0.15) {
        return 'good';
    }
    if ($v <= -0.15) {
        return 'bad';
    }

    return 'muted';
}

/**
 * @param list<string> $paragraphs
 */
function explainBox(array $paragraphs): string
{
    $html = '<div class="explain"><div class="explain-title">Пояснение</div>';
    foreach ($paragraphs as $p) {
        $html .= '<p>' . $p . '</p>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * @param array<string, array<string, mixed>> $volatility
 */
function explainVolatility(array $volatility, string $volMetric, string $marketSymbol): array
{
    if ($volatility === []) {
        return ['Не удалось посчитать волатильность: недостаточно свечей на выбранных таймфреймах.'];
    }
    $ranked = [];
    foreach ($volatility as $tf => $row) {
        $m = $row['primary_mean'] ?? null;
        if ($m === null) {
            continue;
        }
        $ranked[] = ['tf' => (string) $tf, 'mean' => (float) $m];
    }
    usort($ranked, static fn (array $a, array $b): int => $b['mean'] <=> $a['mean']);
    $hi = $ranked[0] ?? null;
    $lo = $ranked !== [] ? $ranked[count($ranked) - 1] : null;

    $p = [];
    $p[] = 'Этот блок — <strong>первый шаг</strong> отчёта. Для каждого таймфрейма по загруженным свечам '
        . htmlspecialchars($marketSymbol, ENT_QUOTES)
        . ' считаются три независимые меры «шумности» рынка, а в колонке <strong>Primary</strong> '
        . 'показывается средняя выбранная метрика (<em>' . htmlspecialchars($volMetric, ENT_QUOTES) . '</em>), '
        . 'которая дальше участвует в корреляции с точностью сигналов.';
    $p[] = '<strong>ATR% mean</strong> — средний ATR(14) в процентах от цены закрытия: насколько типичный '
        . 'бар «широкий» относительно цены. Удобно сравнивать ТФ между собой: на младших ТФ ATR% обычно выше '
        . 'в пересчёте на бар, на старших — ниже.';
    $p[] = '<strong>RV(48) mean</strong> — среднее реализованной волатильности: скользящее стандартное отклонение '
        . 'лог-доходностей за 48 баров (в процентах, без аннуализации). Чувствительнее к сериям резких движений, '
        . 'чем ATR.';
    $p[] = '<strong>BB width mean</strong> — средняя ширина полос Боллинджера (20, 2σ) относительно средней: '
        . '(upper−lower)/mid. Растёт, когда цена «разъезжается» вокруг SMA; падает в сжатии (squeeze).';
    if ($hi !== null && $lo !== null) {
        $p[] = 'По выбранной primary-метрике сейчас самый «шумный» ТФ — <strong>'
            . htmlspecialchars($hi['tf'], ENT_QUOTES) . '</strong> (mean '
            . num($hi['mean'], 4) . '), самый спокойный — <strong>'
            . htmlspecialchars($lo['tf'], ENT_QUOTES) . '</strong> (mean '
            . num($lo['mean'], 4) . '). Именно уровень vol <em>на баре сигнала</em> (не среднее по ТФ) '
            . 'потом сопоставляется с hit/miss каждого метода.';
    }
    $p[] = 'Переключатель Vol сверху меняет только primary-метрику для корреляции; все три столбца mean '
        . 'всегда считаются для сравнения. Больше свечей → стабильнее средние, но окно истории длиннее.';

    return $p;
}

/**
 * @param array<string, array<string, array<string, mixed>>> $matrix
 * @param list<string> $methodOrder
 * @param list<string|int> $tfOrder
 */
function explainMatrix(array $matrix, array $methodOrder, array $tfOrder, string $volMetric): array
{
    $bestAcc = null;
    $bestCorrPos = null;
    $bestCorrNeg = null;
    $cells = 0;
    foreach ($methodOrder as $method) {
        foreach ($tfOrder as $tf) {
            $cell = $matrix[$method][(string) $tf] ?? null;
            if (!is_array($cell)) {
                continue;
            }
            $cells++;
            $acc = isset($cell['accuracy']) ? (float) $cell['accuracy'] : null;
            $corr = isset($cell['corr']) && $cell['corr'] !== null ? (float) $cell['corr'] : null;
            if ($acc !== null && ($bestAcc === null || $acc > $bestAcc['acc'])) {
                $bestAcc = ['method' => $method, 'tf' => (string) $tf, 'acc' => $acc];
            }
            if ($corr !== null && ($bestCorrPos === null || $corr > $bestCorrPos['r'])) {
                $bestCorrPos = ['method' => $method, 'tf' => (string) $tf, 'r' => $corr];
            }
            if ($corr !== null && ($bestCorrNeg === null || $corr < $bestCorrNeg['r'])) {
                $bestCorrNeg = ['method' => $method, 'tf' => (string) $tf, 'r' => $corr];
            }
        }
    }

    $p = [];
    $p[] = 'Это <strong>сводка шагов 2 и 3</strong>: в одной ячейке — доля верных сигналов метода на ТФ '
        . 'и Pearson-корреляция между волатильностью (<em>' . htmlspecialchars($volMetric, ENT_QUOTES)
        . '</em> на баре сигнала) и исходом сигнала (1 = верно, 0 = нет). Заполнено ячеек: <strong>'
        . $cells . '</strong>. Прочерк — меньше 3 пригодных сигналов на этой паре метод×ТФ.';
    $p[] = 'Как читается ячейка: верхняя цифра — <strong>accuracy</strong> (доля попаданий направления). '
        . 'Нижняя <strong>r=…</strong> — корреляция. Зелёный r ≳ +0.15: при росте vol доля попаданий чаще растёт '
        . '(метод «любит» шум). Красный r ≲ −0.15: точнее в спокойном рынке. Около нуля — явной связи нет.';
    if ($bestAcc !== null) {
        $p[] = 'Максимальная точность в матрице: <strong>'
            . htmlspecialchars($bestAcc['method'], ENT_QUOTES) . '</strong> на <strong>'
            . htmlspecialchars($bestAcc['tf'], ENT_QUOTES) . '</strong> — '
            . pct($bestAcc['acc']) . '. Это ещё не «лучший для торговли»: смотрите число сигналов '
            . 'и corr в детальном блоке ниже.';
    }
    if ($bestCorrPos !== null && $bestCorrNeg !== null) {
        $p[] = 'Самая положительная corr: <strong>'
            . htmlspecialchars($bestCorrPos['method'], ENT_QUOTES) . ' / '
            . htmlspecialchars($bestCorrPos['tf'], ENT_QUOTES) . '</strong> (r='
            . num($bestCorrPos['r'], 3) . '). Самая отрицательная: <strong>'
            . htmlspecialchars($bestCorrNeg['method'], ENT_QUOTES) . ' / '
            . htmlspecialchars($bestCorrNeg['tf'], ENT_QUOTES) . '</strong> (r='
            . num($bestCorrNeg['r'], 3) . '). При малой выборке |r| может быть случайным — '
            . 'смотрите колонку «Сигналов» в следующем разделе.';
    }
    $p[] = 'Матрица удобна для быстрого сканирования: по строке видно, на каких ТФ метод стабилен; '
        . 'по столбцу — какие методы работают на данном горизонте. Детали (terciles, PF, return) — в блоках по методам.';

    return $p;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function explainMethod(string $method, array $rows, string $volMetric): array
{
    if ($rows === []) {
        return ['Для метода ' . htmlspecialchars($method, ENT_QUOTES) . ' нет строк с ≥3 сигналами.'];
    }
    $bestAcc = null;
    $bestCorr = null;
    foreach ($rows as $row) {
        $acc = isset($row['accuracy']) ? (float) $row['accuracy'] : null;
        $corr = isset($row['corr_vol_accuracy']) && $row['corr_vol_accuracy'] !== null
            ? (float) $row['corr_vol_accuracy'] : null;
        if ($acc !== null && ($bestAcc === null || $acc > $bestAcc['acc'])) {
            $bestAcc = ['tf' => (string) $row['resolution'], 'acc' => $acc, 'n' => (int) $row['signals']];
        }
        if ($corr !== null && ($bestCorr === null || abs($corr) > abs($bestCorr['r']))) {
            $bestCorr = ['tf' => (string) $row['resolution'], 'r' => $corr, 'n' => (int) $row['signals']];
        }
    }

    $p = [];
    $p[] = 'Таблица для метода <strong>' . htmlspecialchars($method, ENT_QUOTES) . '</strong>: '
        . 'каждая строка — один таймфрейм. Сначала считается точность направления сигнала '
        . '(шаг 2), затем к каждому сигналу привязывается ' . htmlspecialchars($volMetric, ENT_QUOTES)
        . ' на том же баре и строится corr + разбиение по terciles vol (шаг 3).';
    $p[] = 'Колонки: <strong>Точность</strong> — доля верных направлений; <strong>Сигналов</strong> — размер выборки; '
        . '<strong>Vol@signal</strong> — средняя primary-vol именно на барах с сигналом (может отличаться от mean по всему ТФ); '
        . '<strong>corr(vol,hit)</strong> — Pearson; <strong>Acc low/mid/high-vol</strong> — точность в нижней / средней / верхней '
        . 'трети сигналов по vol (в скобках число сигналов в группе); <strong>PF / Return% / Score</strong> — '
        . 'profit factor, доходность простой flip-стратегии и композитный score (как в math-report).';
    if ($bestAcc !== null) {
        $p[] = 'Лучшая точность у этого метода: <strong>'
            . htmlspecialchars($bestAcc['tf'], ENT_QUOTES) . '</strong> — '
            . pct($bestAcc['acc']) . ' при ' . $bestAcc['n'] . ' сигналах.';
    }
    if ($bestCorr !== null) {
        $dir = $bestCorr['r'] >= 0
            ? 'положительная связь: попадания чаще при более высокой vol'
            : 'отрицательная связь: попадания чаще при более низкой vol';
        $p[] = 'Сильнейшая по модулю corr: <strong>'
            . htmlspecialchars($bestCorr['tf'], ENT_QUOTES) . '</strong>, r='
            . num($bestCorr['r'], 3) . ' (' . $bestCorr['n'] . ' сигналов) — ' . $dir . '. '
            . 'Сравнивайте Acc low-vol vs Acc high-vol: если high заметно выше low при положительном r — картина согласована; '
            . 'если расходится — выборка шумная или зависимость нелинейная.';
    }
    $p[] = 'Практический вывод по методу: если на нужном ТФ accuracy приемлема и Acc high-vol выше Acc low-vol '
        . '(или наоборот при отрицательном r) — имеет смысл фильтровать входы по уровню волатильности. '
        . 'Если terciles почти равны — фильтр по vol, скорее всего, не поможет.';

    return $p;
}

/**
 * @param list<array<string, mixed>> $results
 */
function explainRank(array $results, string $volMetric): array
{
    $slice = array_slice($results, 0, 40);
    $p = [];
    $p[] = 'Заключительный блок сортирует все пары метод×ТФ по <strong>|corr|</strong> '
        . '(сила связи accuracy-исхода с ' . htmlspecialchars($volMetric, ENT_QUOTES)
        . '). Это не рейтинг прибыльности: сверху могут оказаться пары с умеренной точностью, '
        . 'но с явной зависимостью от режима vol.';
    $p[] = 'Колонка <strong>Интерпретация</strong>: «лучше при высокой vol» при r ≥ +0.2; '
        . '«лучше при низкой vol» при r ≤ −0.2; иначе «слабо зависит от vol». '
        . 'Порог условный — при малом числе сигналов даже |r|≈0.3 может быть случайным.';
    if ($slice !== []) {
        $top = $slice[0];
        $corr = isset($top['corr_vol_accuracy']) && $top['corr_vol_accuracy'] !== null
            ? (float) $top['corr_vol_accuracy'] : null;
        $p[] = 'Первая строка сейчас: <strong>'
            . htmlspecialchars((string) $top['method'], ENT_QUOTES) . '</strong> на <strong>'
            . htmlspecialchars((string) $top['resolution'], ENT_QUOTES) . '</strong> — точность '
            . pct(isset($top['accuracy']) ? (float) $top['accuracy'] : null)
            . ', corr ' . num($corr, 3) . ', сигналов ' . (int) ($top['signals'] ?? 0) . '. '
            . 'Имеет смысл проверить её детальную таблицу выше (terciles и PF), прежде чем менять правила входа.';
    }
    $p[] = 'Как пользоваться отчётом целиком: (1) по таблице vol понять, какие ТФ сейчас шумные; '
        . '(2) по матрице выбрать кандидатов метод×ТФ; (3) в блоке метода убедиться, что terciles и corr согласованы '
        . 'и сигналов достаточно; (4) по рангу найти режимы, где vol-фильтр даёт наибольший эффект. '
        . 'Комиссии и проскальзывание не учтены.';

    return $p;
}

$volatility = $report['volatility'] ?? [];
$results = $report['results'] ?? [];
$byMethod = $report['by_method'] ?? [];
$matrix = $report['matrix'] ?? [];
$tfOrder = array_keys($volatility);
$methodOrder = TechnicalAnalysis::METHODS;

$qs = static function (array $extra = []) use ($marketId, $candleCount, $volMetric): string {
    return http_build_query(array_merge([
        'market_id' => $marketId,
        'candles' => $candleCount,
        'vol' => $volMetric,
    ], $extra));
};

?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vol × точность · <?= htmlspecialchars($marketSymbol, ENT_QUOTES) ?> #<?= (int) $marketId ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #eef2ef;
      --bg-2: #e4ebe5;
      --ink: #152019;
      --muted: #5c6b61;
      --line: #c7d2cb;
      --panel: #f7faf7;
      --accent: #0f6b4c;
      --ask: #b42318;
      --shadow: 0 18px 50px rgba(21, 32, 25, 0.08);
      --radius: 18px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: var(--ink);
      font-family: Manrope, sans-serif;
      background:
        radial-gradient(1100px 520px at 8% -10%, #d9ebe0 0%, transparent 55%),
        radial-gradient(900px 480px at 100% 0%, #f3e2d4 0%, transparent 50%),
        linear-gradient(180deg, var(--bg), var(--bg-2));
    }
    .wrap {
      width: min(1240px, calc(100% - 2rem));
      margin: 0 auto;
      padding: 2rem 0 3rem;
    }
    .brand {
      font-size: clamp(1.7rem, 3.5vw, 2.4rem);
      font-weight: 700;
      letter-spacing: -0.04em;
    }
    .brand span { color: var(--accent); }
    .subtitle { color: var(--muted); margin: 0.45rem 0 1.25rem; max-width: 44rem; line-height: 1.45; }
    .nav a { color: var(--accent); font-weight: 600; text-decoration: none; margin-left: 0.85rem; }
    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.1rem 1.2rem;
      margin-bottom: 1rem;
    }
    .panel h2 { margin: 0 0 0.75rem; font-size: 1.05rem; }
    .panel h3 {
      margin: 1.1rem 0 0.55rem;
      font-size: 0.95rem;
      color: var(--ink);
    }
    .note { color: var(--muted); font-size: 0.9rem; line-height: 1.45; }
    .error { color: var(--ask); font-weight: 600; }
    .good { color: var(--accent); }
    .bad { color: var(--ask); }
    .muted { color: var(--muted); }
    .scroll { overflow: auto; }
    .steps {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 0.75rem;
      margin-bottom: 0.85rem;
    }
    .steps div {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 0.7rem 0.85rem;
      font-size: 0.88rem;
    }
    .steps strong {
      display: block;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--muted);
      margin-bottom: 0.25rem;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.76rem;
    }
    th, td {
      padding: 0.42rem 0.35rem;
      border-bottom: 1px solid var(--line);
      text-align: right;
      white-space: nowrap;
    }
    th:first-child, td:first-child,
    th.left, td.left { text-align: left; }
    th { color: var(--muted); font-weight: 500; }
    tr:hover td { background: rgba(15, 107, 76, 0.05); }
    .switch {
      display: inline-flex;
      gap: 0.35rem;
      flex-wrap: wrap;
      align-items: center;
    }
    .switch .label {
      color: var(--muted);
      font-size: 0.85rem;
      margin-right: 0.25rem;
    }
    .switch a {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 3.2rem;
      padding: 0.4rem 0.7rem;
      border: 1px solid var(--line);
      border-radius: 999px;
      background: #fff;
      color: var(--ink);
      text-decoration: none;
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.78rem;
      font-weight: 500;
    }
    .switch a:hover { border-color: var(--accent); color: var(--accent); }
    .switch a.active {
      background: var(--accent);
      border-color: var(--accent);
      color: #fff;
    }
    .method-block {
      border-top: 1px dashed var(--line);
      padding-top: 0.85rem;
      margin-top: 0.85rem;
    }
    .method-block:first-of-type { border-top: 0; padding-top: 0; margin-top: 0; }
    .method-title {
      font-weight: 700;
      font-size: 1rem;
      margin: 0 0 0.45rem;
    }
    .hint {
      font-size: 0.82rem;
      color: var(--muted);
      margin: 0 0 0.55rem;
      line-height: 1.4;
    }
    .explain {
      margin-top: 0.95rem;
      padding: 0.85rem 1rem;
      background: #fff;
      border: 1px solid var(--line);
      border-left: 4px solid var(--accent);
      border-radius: 12px;
    }
    .explain-title {
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 0.45rem;
    }
    .explain p {
      margin: 0 0 0.55rem;
      font-size: 0.9rem;
      line-height: 1.5;
      color: var(--ink);
    }
    .explain p:last-child { margin-bottom: 0; }
    @media (max-width: 800px) {
      .steps { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:end">
      <div>
        <div class="brand">Vol × точность <span>· <?= htmlspecialchars($marketSymbol, ENT_QUOTES) ?> #<?= (int) $marketId ?></span></div>
        <p class="subtitle">
          Отдельный отчёт: сначала волатильность по ТФ, затем точность методов,
          затем корреляция точности с волатильностью на момент сигнала.
        </p>
      </div>
      <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center">
        <div class="switch" aria-label="Рынок">
          <span class="label">Рынок:</span>
          <a class="<?= $marketId === 120 ? 'active' : '' ?>" href="?<?= htmlspecialchars($qs(['market_id' => 120]), ENT_QUOTES) ?>">LIT</a>
          <a class="<?= $marketId === 1 ? 'active' : '' ?>" href="?<?= htmlspecialchars($qs(['market_id' => 1]), ENT_QUOTES) ?>">BTC</a>
        </div>
        <div class="nav">
          <a href="index.php">← стартовая</a>
          <a href="math-report.php?market_id=<?= (int) $marketId ?>">math-report</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="switch" style="margin-bottom:0.75rem" aria-label="Свечи">
        <span class="label">Свечей:</span>
        <?php foreach ($allowedCandleCounts as $n): ?>
          <a class="<?= $candleCount === $n ? 'active' : '' ?>" href="?<?= htmlspecialchars($qs(['candles' => $n]), ENT_QUOTES) ?>"><?= (int) $n ?></a>
        <?php endforeach; ?>
      </div>
      <div class="switch" aria-label="Метрика волатильности">
        <span class="label">Vol:</span>
        <?php foreach (TechnicalAnalysis::VOLATILITY_METHODS as $vm): ?>
          <a class="<?= $volMetric === $vm ? 'active' : '' ?>" href="?<?= htmlspecialchars($qs(['vol' => $vm]), ENT_QUOTES) ?>"><?= htmlspecialchars($vm, ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($error !== null): ?>
      <div class="panel"><p class="error">Ошибка: <?= htmlspecialchars($error, ENT_QUOTES) ?></p></div>
    <?php else: ?>
      <div class="panel">
        <h2>Пайплайн отчёта</h2>
        <div class="steps">
          <div><strong>1 · Волатильность</strong>Среднее ATR%, RV(48), BB width по каждому ТФ; корреляция считается по выбранной метрике <em><?= htmlspecialchars($volMetric, ENT_QUOTES) ?></em>.</div>
          <div><strong>2 · Точность методов</strong>Направление сигнала vs движение цены через <?= (int) $horizon ?> бар(а). Без комиссий.</div>
          <div><strong>3 · Корреляция</strong>Pearson: vol на баре сигнала ↔ hit (1/0). Плюс точность в terciles низкая/средняя/высокая vol.</div>
        </div>
        <p class="note">
          Запрошено <?= (int) $candleCount ?> свечей · загружено:
          <?php foreach ($loaded as $tf => $count): ?>
            <strong><?= htmlspecialchars((string) $tf) ?></strong>=<?= (int) $count ?><?= $tf === array_key_last($loaded) ? '' : ',' ?>
          <?php endforeach; ?>.
          corr &gt; 0 → метод точнее при высокой vol; corr &lt; 0 → лучше в спокойном рынке.
        </p>
        <?= explainBox([
            'Отчёт строится строго сверху вниз. Сначала описывается «фон» рынка — насколько широки движения на каждом ТФ. '
            . 'Без этого нельзя интерпретировать корреляцию: одна и та же accuracy на 15m и на 1d означает разный торговый контекст.',
            'Затем для каждого направленного метода (MACD, RSI, SMA/EMA cross, Bollinger bounce, ROC, Momentum) '
            . 'на каждом ТФ считается доля верных сигналов: после сигнала через '
            . (int) $horizon . ' бар цена пошла в предсказанную сторону. Плоские бары (нулевое движение) в hit/miss не входят.',
            'В конце к каждому сигналу приклеивается значение выбранной vol-метрики на том же баре. '
            . 'По парам (vol, hit) считается Pearson r и точность в трёх корзинах волатильности. '
            . 'Ниже под каждой таблицей — текстовый разбор с выводами по текущим цифрам (не шаблон «на все случаи»).',
        ]) ?>
      </div>

      <div class="panel">
        <h2>1. Волатильность по таймфреймам</h2>
        <div class="scroll">
          <table>
            <thead>
              <tr>
                <th class="left">ТФ</th>
                <th>Свечей</th>
                <th>ATR% mean</th>
                <th>RV(48) mean</th>
                <th>BB width mean</th>
                <th>Primary (<?= htmlspecialchars($volMetric, ENT_QUOTES) ?>)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($volatility as $tf => $row): ?>
                <tr>
                  <td class="left"><?= htmlspecialchars((string) $tf) ?></td>
                  <td><?= (int) $row['candles'] ?></td>
                  <td><?= num($row['atr_pct_mean'] !== null ? (float) $row['atr_pct_mean'] : null, 3) ?></td>
                  <td><?= num($row['rv_log_mean'] !== null ? (float) $row['rv_log_mean'] : null, 3) ?></td>
                  <td><?= num($row['bb_width_mean'] !== null ? (float) $row['bb_width_mean'] : null, 4) ?></td>
                  <td><strong><?= num($row['primary_mean'] !== null ? (float) $row['primary_mean'] : null, 4) ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?= explainBox(explainVolatility($volatility, $volMetric, $marketSymbol)) ?>
      </div>

      <div class="panel">
        <h2>2–3. Матрица: метод × ТФ (точность / corr)</h2>
        <p class="hint">Ячейка: accuracy · corr(vol, hit). Пусто — мало сигналов (&lt;3).</p>
        <div class="scroll">
          <table>
            <thead>
              <tr>
                <th class="left">Метод</th>
                <?php foreach ($tfOrder as $tf): ?>
                  <th><?= htmlspecialchars((string) $tf) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($methodOrder as $method): ?>
                <tr>
                  <td class="left"><?= htmlspecialchars($method, ENT_QUOTES) ?></td>
                  <?php foreach ($tfOrder as $tf): ?>
                    <?php
                      $cell = $matrix[$method][$tf] ?? null;
                      $acc = is_array($cell) ? ($cell['accuracy'] ?? null) : null;
                      $corr = is_array($cell) ? ($cell['corr'] ?? null) : null;
                    ?>
                    <td>
                      <?php if ($cell === null): ?>
                        <span class="muted">—</span>
                      <?php else: ?>
                        <?= pct(is_float($acc) || is_int($acc) ? (float) $acc : null) ?>
                        <br>
                        <span class="<?= corrClass(is_float($corr) || is_int($corr) ? (float) $corr : null) ?>">
                          r=<?= num(is_float($corr) || is_int($corr) ? (float) $corr : null, 2) ?>
                        </span>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?= explainBox(explainMatrix($matrix, $methodOrder, $tfOrder, $volMetric)) ?>
      </div>

      <div class="panel">
        <h2>Отчёт по каждому методу и ТФ</h2>
        <?php if ($byMethod === []): ?>
          <p class="error">Недостаточно сигналов для отчёта.</p>
        <?php else: ?>
          <?php foreach ($methodOrder as $method): ?>
            <?php
              $rows = $byMethod[$method] ?? [];
              if ($rows === []) {
                  continue;
              }
              usort($rows, static function (array $a, array $b) use ($tfOrder): int {
                  $ia = array_search($a['resolution'], $tfOrder, true);
                  $ib = array_search($b['resolution'], $tfOrder, true);
                  $ia = $ia === false ? 999 : $ia;
                  $ib = $ib === false ? 999 : $ib;

                  return $ia <=> $ib;
              });
            ?>
            <div class="method-block">
              <div class="method-title"><?= htmlspecialchars($method, ENT_QUOTES) ?></div>
              <div class="scroll">
                <table>
                  <thead>
                    <tr>
                      <th class="left">ТФ</th>
                      <th>Точность</th>
                      <th>Сигналов</th>
                      <th>Vol@signal</th>
                      <th>corr(vol,hit)</th>
                      <th>Acc low-vol</th>
                      <th>Acc mid-vol</th>
                      <th>Acc high-vol</th>
                      <th>PF</th>
                      <th>Return%</th>
                      <th>Score</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <?php
                        $corr = $row['corr_vol_accuracy'];
                        $corrF = is_float($corr) || is_int($corr) ? (float) $corr : null;
                      ?>
                      <tr>
                        <td class="left"><?= htmlspecialchars((string) $row['resolution']) ?></td>
                        <td class="good"><?= pct(isset($row['accuracy']) ? (float) $row['accuracy'] : null) ?></td>
                        <td><?= (int) $row['signals'] ?></td>
                        <td><?= num(isset($row['vol_mean_at_signals']) && $row['vol_mean_at_signals'] !== null ? (float) $row['vol_mean_at_signals'] : null, 3) ?></td>
                        <td class="<?= corrClass($corrF) ?>"><?= num($corrF, 3) ?></td>
                        <td><?= pct(isset($row['accuracy_vol_low']) && $row['accuracy_vol_low'] !== null ? (float) $row['accuracy_vol_low'] : null) ?>
                          <span class="muted">(<?= (int) $row['n_vol_low'] ?>)</span></td>
                        <td><?= pct(isset($row['accuracy_vol_mid']) && $row['accuracy_vol_mid'] !== null ? (float) $row['accuracy_vol_mid'] : null) ?>
                          <span class="muted">(<?= (int) $row['n_vol_mid'] ?>)</span></td>
                        <td><?= pct(isset($row['accuracy_vol_high']) && $row['accuracy_vol_high'] !== null ? (float) $row['accuracy_vol_high'] : null) ?>
                          <span class="muted">(<?= (int) $row['n_vol_high'] ?>)</span></td>
                        <td><?= $row['profit_factor'] === null ? '—' : num((float) $row['profit_factor'], 2) ?></td>
                        <td class="<?= ((float) ($row['strategy_return_pct'] ?? 0)) >= 0 ? 'good' : 'bad' ?>"><?= num((float) ($row['strategy_return_pct'] ?? 0), 2) ?></td>
                        <td><?= num((float) ($row['score'] ?? 0), 3) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?= explainBox(explainMethod($method, $rows, $volMetric)) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="panel">
        <h2>Ранг по |corr| (все пары метод×ТФ)</h2>
        <div class="scroll">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th class="left">Метод</th>
                <th class="left">ТФ</th>
                <th>Точность</th>
                <th>corr</th>
                <th>Сигналов</th>
                <th>Интерпретация</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_slice($results, 0, 40) as $i => $row): ?>
                <?php
                  $corr = $row['corr_vol_accuracy'];
                  $corrF = is_float($corr) || is_int($corr) ? (float) $corr : null;
                  $interp = 'мало данных';
                  if ($corrF !== null) {
                      if ($corrF >= 0.2) {
                          $interp = 'лучше при высокой vol';
                      } elseif ($corrF <= -0.2) {
                          $interp = 'лучше при низкой vol';
                      } else {
                          $interp = 'слабо зависит от vol';
                      }
                  }
                ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td class="left"><?= htmlspecialchars((string) $row['method'], ENT_QUOTES) ?></td>
                  <td class="left"><?= htmlspecialchars((string) $row['resolution']) ?></td>
                  <td><?= pct(isset($row['accuracy']) ? (float) $row['accuracy'] : null) ?></td>
                  <td class="<?= corrClass($corrF) ?>"><?= num($corrF, 3) ?></td>
                  <td><?= (int) $row['signals'] ?></td>
                  <td class="left"><?= htmlspecialchars($interp, ENT_QUOTES) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?= explainBox(explainRank($results, $volMetric)) ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
