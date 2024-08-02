function initializeCharts(distributionData, topSimilarPagesData) {
    require(['TYPO3/CMS/Backend/Chart'], function(Chart) {
        new Chart(document.getElementById('similarityChart').getContext('2d'), {
            type: 'bar',
            data: distributionData,
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });

        new Chart(document.getElementById('topSimilarPages').getContext('2d'), {
            type: 'horizontalBar',
            data: topSimilarPagesData,
            options: {
                responsive: true,
                scales: { x: { beginAtZero: true } }
            }
        });
    });
}