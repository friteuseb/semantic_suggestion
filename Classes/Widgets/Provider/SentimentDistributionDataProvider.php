<?php
namespace TalanHdf\SemanticSuggestion\Widgets\Provider;

use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

class SentimentDistributionDataProvider implements ChartDataProviderInterface
{
    private $sentimentDistribution;

    public function __construct(array $sentimentDistribution)
    {
        $this->sentimentDistribution = $sentimentDistribution;
    }

    public function getChartData(): array
    {
        return [
            'labels' => array_keys($this->sentimentDistribution),
            'datasets' => [
                [
                    'data' => array_values($this->sentimentDistribution),
                    'backgroundColor' => [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#4BC0C0',
                        '#9966FF'
                    ],
                ]
            ]
        ];
    }
}