<?php
namespace TalanHdf\SemanticSuggestion\Widgets;

use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\EventDataInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\RequireJsModuleInterface;
use TalanHdf\SemanticSuggestion\Widgets\Provider\SentimentDistributionDataProvider;

class SentimentDistributionWidget implements WidgetInterface, EventDataInterface, AdditionalCssInterface, RequireJsModuleInterface
{
    private $dataProvider;

    public function __construct(SentimentDistributionDataProvider $dataProvider)
    {
        $this->dataProvider = $dataProvider;
    }

    public function renderWidgetContent(): string
    {
        return '<div id="sentimentDistributionChart" style="width: 100%; height: 300px;"></div>';
    }

    public function getEventData(): array
    {
        return [
            'graphConfig' => [
                'type' => 'pie',
                'options' => [
                    'responsive' => true,
                    'plugins' => [
                        'legend' => [
                            'position' => 'top',
                        ],
                        'title' => [
                            'display' => true,
                            'text' => 'Distribution des sentiments'
                        ]
                    ]
                ],
                'data' => $this->dataProvider->getChartData(),
            ],
        ];
    }

    public function getCssFiles(): array
    {
        return [];
    }

    public function getRequireJsModules(): array
    {
        return [
            'TYPO3/CMS/Dashboard/Contrib/chartjs',
            'TYPO3/CMS/Dashboard/ChartInitializer',
        ];
    }
}