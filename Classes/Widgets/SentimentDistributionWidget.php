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
    private $options;

    public function __construct(SentimentDistributionDataProvider $dataProvider, array $options = [])
    {
        $this->dataProvider = $dataProvider;
        $this->options = $options;
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

    // Ajout de la méthode manquante
    public function getOptions(): array
    {
        return $this->options;
    }
}