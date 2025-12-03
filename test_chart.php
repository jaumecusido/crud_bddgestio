<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test Chart local</title>
    <!-- IMPORTANT: ruta local a Chart.js -->
    <script src="/js/chart.umd.min.js"></script>
</head>
<body style="background:#020617;color:#e5e7eb;">
    <h1>Prova Chart.js LOCAL</h1>
    <p>A sota ha d’aparèixer un gràfic de pastís.</p>

    <canvas> id="chartClients" width="300" height="300" style="background:#111827;"></canvas>

    <script>
        console.log('Script carregat');
        console.log('Chart:', typeof Chart);

        const canvas = document.getElementById('chartClients');
        console.log('Canvas:', canvas);

        if (canvas && typeof Chart !== 'undefined') {
            const ctx = canvas.getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: ['A','B','C'],
                    datasets: [{
                        data: [10, 20, 30],
                        backgroundColor: ['#22c55e','#0ea5e9','#a855f7']
                    }]
                }
            });
        } else {
            console.error('No hi ha canvas o Chart no està definit');
        }
    </script>
</body>
</html>
