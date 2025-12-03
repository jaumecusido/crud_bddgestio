<?php
require_once 'db.php';
$pdo = getPDO();


// FILTRES
$dataInici = $_GET['data_inici'] ?? '';
$dataFi    = $_GET['data_fi'] ?? '';
$clientId  = $_GET['client_id'] ?? '';
$articleId = $_GET['article_id'] ?? '';


// Carregar clients per al select
$clientsStmt = $pdo->query("SELECT id, nom FROM clients ORDER BY nom");
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);


// Carregar articles per al select
$articlesStmt = $pdo->query("SELECT id, codi, descripcio FROM articles ORDER BY codi");
$articles = $articlesStmt->fetchAll(PDO::FETCH_ASSOC);


// Construir condicions comunes
$where = [];
$params = [];


// Dates (HTML input date: YYYY-MM-DD)
if ($dataInici !== '') {
    $where[] = "f.data_factura >= :data_inici";
    $params['data_inici'] = $dataInici;
}
if ($dataFi !== '') {
    $where[] = "f.data_factura <= :data_fi";
    $params['data_fi'] = $dataFi;
}


// Client
if ($clientId !== '' && ctype_digit((string)$clientId)) {
    $where[] = "f.client_id = :client_id";
    $params['client_id'] = (int)$clientId;
}


// Article (via factures -> factura_albarans -> albarans -> albara_linies)
if ($articleId !== '' && ctype_digit((string)$articleId)) {
    $where[] = "EXISTS (
        SELECT 1
        FROM factura_albarans fa2
        JOIN albara_linies l2 ON l2.albara_id = fa2.albara_id
        WHERE fa2.factura_id = f.id
          AND l2.article_id = :article_id
    )";
    $params['article_id'] = (int)$articleId;
}


$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';


// 1) Estadística per client (sumem import_total de factures)
$sqlClients = "
    SELECT COALESCE(c.nom, 'Sense client') AS etiqueta,
           SUM(f.import_total) AS total
    FROM factures f
    LEFT JOIN clients c ON c.id = f.client_id
    $whereSql
    GROUP BY c.nom
    ORDER BY total DESC
";
$stmt = $pdo->prepare($sqlClients);
$stmt->execute($params);
$rowsClients = $stmt->fetchAll(PDO::FETCH_ASSOC);


// 2) Estadística per article (sumant línies d’albarà facturades)
$sqlArticles = "
    SELECT a.descripcio AS etiqueta,
           COALESCE(SUM(l.preu_unitari * l.quantitat),0) AS total
    FROM factures f
    JOIN factura_albarans fa ON fa.factura_id = f.id
    JOIN albarans alb ON alb.id = fa.albara_id
    JOIN albara_linies l ON l.albara_id = alb.id
    JOIN articles a ON a.id = l.article_id
    $whereSql
    GROUP BY a.descripcio
    ORDER BY total DESC
";
$stmt = $pdo->prepare($sqlArticles);
$stmt->execute($params);
$rowsArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);


// 3) Estadística per període (YYYY-MM)
$sqlMesos = "
    SELECT to_char(f.data_factura, 'YYYY-MM') AS etiqueta,
           SUM(f.import_total) AS total
    FROM factures f
    $whereSql
    GROUP BY to_char(f.data_factura, 'YYYY-MM')
    ORDER BY etiqueta
";
$stmt = $pdo->prepare($sqlMesos);
$stmt->execute($params);
$rowsMesos = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Preparar dades per Chart.js
function buildChartData(array $rows): array {
    $labels = [];
    $data   = [];
    $total  = 0.0;


    foreach ($rows as $r) {
        $labels[] = $r['etiqueta'] !== '' ? $r['etiqueta'] : 'Sense nom';
        $val = (float)$r['total'];
        $data[] = $val;
        $total += $val;
    }


    // Si no hi ha dades, posem un troç dummy
    if ($total === 0 && empty($labels)) {
        $labels = ['Sense dades'];
        $data   = [1];
        $total  = 1;
    }


    return [
        'labels' => $labels,
        'data'   => $data,
        'total'  => $total
    ];
}


$chartClients  = buildChartData($rowsClients);
$chartArticles = buildChartData($rowsArticles);
$chartMesos    = buildChartData($rowsMesos);


// 4) Sèrie temporal per línia (per mes; pots canviar a 'YYYY-MM-DD' si vols dia a dia)
$sqlTime = "
    SELECT to_char(f.data_factura, 'YYYY-MM-01') AS data_point,
           SUM(f.import_total) AS total
    FROM factures f
    $whereSql
    GROUP BY to_char(f.data_factura, 'YYYY-MM-01')
    ORDER BY data_point
";
$stmt = $pdo->prepare($sqlTime);
$stmt->execute($params);
$rowsTime = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Per a la línia, fem servir labels = dates i data = totals
function buildTimeSeries(array $rows): array {
    $labels = [];
    $data   = [];
    $total  = 0.0;


    foreach ($rows as $r) {
        $labels[] = $r['data_point'];
        $val = (float)$r['total'];
        $data[] = $val;
        $total += $val;
    }


    if (empty($labels)) {
        $labels = ['Sense dades'];
        $data   = [0];
    }


    return [
        'labels' => $labels,
        'data'   => $data,
        'total'  => $total
    ];
}


// ja tens buildChartData a dalt, afegeix només aquesta línia:
$chartTimeSerie = buildTimeSeries($rowsTime);





?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Estadística facturació</title>
    <!-- IMPORTANT: Chart.js local. Ajusta el path si cal. -->
    <script src="/js/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #020617;
            --card-bg: rgba(15,23,42,0.96);
            --border-soft: rgba(148,163,184,0.5);
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;
            --accent: #22c55e;
            --accent-soft: rgba(34,197,94,0.16);
            --filter-bg: rgba(15,23,42,0.9);
            --filter-border: rgba(148,163,184,0.75);
        }


        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }


        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top, rgba(34,197,94,0.2) 0, rgba(15,23,42,1) 45%, #000 100%);
            color: var(--text-main);
            padding: 14px 16px;
        }


        .page {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }


        .header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            border-bottom: 1px solid rgba(31,41,55,0.9);
            padding-bottom: 6px;
        }


        .title-block h1 {
            font-size: 1rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }


        .title-block p {
            font-size: 0.8rem;
            color: var(--text-soft);
            margin-top: 2px;
        }


        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background-color: var(--accent-soft);
            border: 1px solid rgba(34,197,94,0.6);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #bbf7d0;
        }


        .pill span.icon {
            font-size: 0.95rem;
        }


        .filters {
            margin-top: 4px;
            padding: 8px 10px;
            border-radius: 14px;
            background-color: var(--filter-bg);
            border: 1px solid var(--filter-border);
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            align-items: center;
            font-size: 0.8rem;
        }


        .filters label {
            color: var(--text-soft);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }


        .filters input[type="date"],
        .filters select {
            margin-left: 4px;
            padding: 4px 6px;
            border-radius: 999px;
            border: 1px solid rgba(148,163,184,0.9);
            background-color: rgba(15,23,42,0.9);
            color: var(--text-main);
            font-size: 0.8rem;
        }


        .filters button {
            margin-left: auto;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid rgba(34,197,94,0.9);
            background-color: rgba(34,197,94,0.16);
            color: #bbf7d0;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            cursor: pointer;
        }


        .filters a.clear-link {
            font-size: 0.8rem;
            color: var(--text-soft);
            text-decoration: underline;
        }


        .cards-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0,1fr));
            gap: 10px;
            margin-top: 8px;
        }


        .card {
            background-color: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-soft);
            padding: 10px 10px 8px;
            box-shadow:
                0 18px 40px rgba(15,23,42,0.8),
                0 0 0 1px rgba(30,64,175,0.5);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }


        .card h2 {
            font-size: 0.8rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            margin-bottom: 2px;
        }


        .card .subtitle {
            font-size: 0.75rem;
            color: var(--text-soft);
        }


        .chart-wrapper {
            position: relative;
            width: 100%;
            height: 220px;
        }


        .total-label {
            font-size: 0.8rem;
            color: var(--text-soft);
            margin-top: 2px;
        }


        @media (max-width: 980px) {
            .cards-row {
                grid-template-columns: repeat(2, minmax(0,1fr));
            }
        }


        @media (max-width: 720px) {
            .cards-row {
                grid-template-columns: 1fr;
            }
            .filters {
                flex-direction: column;
                align-items: flex-start;
            }
            .filters button {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="title-block">
            <h1>Estadística facturació</h1>
            <p>Distribució d’imports per clients, articles i períodes.</p>
        </div>
        <div class="pill">
            <span class="icon">📊</span>
            Quadre de comandament
        </div>
    </div>


    <form method="get" class="filters">
        <label>Data inici
            <input type="date" name="data_inici"
                   value="<?= htmlspecialchars($dataInici) ?>">
        </label>
        <label>Data fi
            <input type="date" name="data_fi"
                   value="<?= htmlspecialchars($dataFi) ?>">
        </label>
        <label>Client
            <select name="client_id">
                <option value="">Tots</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>"
                        <?= ($clientId !== '' && (int)$clientId === (int)$c['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Article
            <select name="article_id">
                <option value="">Tots</option>
                <?php foreach ($articles as $a): ?>
                    <option value="<?= $a['id'] ?>"
                        <?= ($articleId !== '' && (int)$articleId === (int)$a['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['codi'] . ' - ' . $a['descripcio']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Aplicar filtres</button>
        <?php if ($dataInici || $dataFi || $clientId || $articleId): ?>
            <a href="informes_estadistica_facturacio.php" class="clear-link">Treure filtres</a>
        <?php endif; ?>
    </form>


    <div class="cards-row">
        <section class="card">
    <h2>Per client</h2>
    <p class="subtitle">Percentatge d’import facturat per cada client.</p>
    <div class="chart-wrapper" align="center">
        <canvas id="chartClients"></canvas>
    </div>
    <div class="total-label" align="center">
        Total: <?= htmlspecialchars(number_format($chartClients['total'], 2, ',', '.')) ?> €
    </div>
</section>


<section class="card">
    <h2>Per article</h2>
    <p class="subtitle">Ingressos acumulats per article.</p>
    <div class="chart-wrapper" align="center">
        <canvas id="chartArticles"></canvas>
    </div>
    <div class="total-label" align="center">
        Total: <?= htmlspecialchars(number_format($chartArticles['total'], 2, ',', '.')) ?> €
    </div>
</section>


<section class="card">
    <h2>Per període</h2>
    <p class="subtitle">Import total per mes (YYYY-MM).</p>
    <div class="chart-wrapper" align="center">
        <canvas id="chartMesos"></canvas>
    </div>
    <div class="total-label" align="center">
        Total: <?= htmlspecialchars(number_format($chartMesos['total'], 2, ',', '.')) ?> €
    </div>
</section>




    </div>
    <section class="card card-full">
    <div class="timeline-title">
        <h2>Línia temporal facturació</h2>
        <span>Import total per mes (evolució en el temps)</span>
    </div>
    <div class="chart-wrapper" style="height:260px;" align="center">
        <canvas id="chartTimeline"></canvas>
    </div>
    <div class="total-label" align="center">
        Suma total període: <?= htmlspecialchars(number_format($chartTimeSerie['total'], 2, ',', '.')) ?> €
    </div>
</section>
</div>


<script>
    const dataClients  = <?= json_encode($chartClients, JSON_UNESCAPED_UNICODE) ?>;
    const dataArticles = <?= json_encode($chartArticles, JSON_UNESCAPED_UNICODE) ?>;
    const dataMesos    = <?= json_encode($chartMesos, JSON_UNESCAPED_UNICODE) ?>;
    const dataTimeline = <?= json_encode($chartTimeSerie, JSON_UNESCAPED_UNICODE) ?>;


    function makePieConfig(chartData) {
        const total  = chartData.total || 0;
        const labels = chartData.labels || [];
        const values = chartData.data || [];


        const backgroundColors = [
            '#22c55e','#0ea5e9','#a855f7','#f97316','#ef4444',
            '#eab308','#14b8a6','#6366f1','#facc15','#4ade80'
        ];


        return {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: labels.map((_, i) => backgroundColors[i % backgroundColors.length]),
                    borderColor: '#020617',
                    borderWidth: 1
                }]
            },
            options: {
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#e5e7eb',
                            font: { size: 11 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const pct = total > 0 ? (value / total * 100) : 0;
                                return label + ': ' +
                                    value.toLocaleString('ca-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2}) +
                                    ' € (' + pct.toFixed(1) + ' %)';
                            }
                        }
                    }
                }
            }
        };
    }


    const ctxClients  = document.getElementById('chartClients').getContext('2d');
    const ctxArticles = document.getElementById('chartArticles').getContext('2d');
    const ctxMesos    = document.getElementById('chartMesos').getContext('2d');
    const ctxTimeline = document.getElementById('chartTimeline').getContext('2d');


    new Chart(ctxClients,  makePieConfig(dataClients));
    new Chart(ctxArticles, makePieConfig(dataArticles));
    new Chart(ctxMesos,    makePieConfig(dataMesos));

    // GRÀFICA LÍNIA TEMPORAL
    const labelsTime = dataTimeline.labels || [];
    const valuesTime = dataTimeline.data   || [];

    const prettyLabelsTime = labelsTime.map(function(d) {
        if (!d || d === 'Sense dades') return d;
        const parts = d.split('-'); // [YYYY, MM, DD]
        return parts[1] + '/' + parts[0];
    });

    const gradientLine = ctxTimeline.createLinearGradient(0, 0, 0, 260);
    gradientLine.addColorStop(0, 'rgba(34,197,94,0.6)');
    gradientLine.addColorStop(0.6, 'rgba(34,197,94,0.15)');
    gradientLine.addColorStop(1, 'rgba(34,197,94,0)');

    new Chart(ctxTimeline, {
        type: 'line',
        data: {
            labels: prettyLabelsTime,
            datasets: [{
                label: 'Facturació mensual',
                data: valuesTime,
                borderColor: '#22c55e',
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#22c55e',
                tension: 0.35,
                fill: true,
                backgroundColor: gradientLine
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    labels: {
                        color: '#e5e7eb',
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const v = context.parsed.y || 0;
                            return v.toLocaleString('ca-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#9ca3af',
                        autoSkip: true,
                        maxTicksLimit: 12
                    },
                    grid: {
                        color: 'rgba(55,65,81,0.6)'
                    }
                },
                y: {
                    ticks: {
                        color: '#9ca3af',
                        callback: function(value) {
                            return value.toLocaleString('ca-ES', {maximumFractionDigits: 0}) + ' €';
                        }
                    },
                    grid: {
                        color: 'rgba(31,41,55,0.6)'
                    }
                }
            }
        }
    });
</script>
</body>
</html>
