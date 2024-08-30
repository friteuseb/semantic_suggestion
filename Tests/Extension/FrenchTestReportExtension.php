<?php

namespace TalanHdf\SemanticSuggestion\Tests\Extension;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

class FrenchTestReportExtension implements Extension
{
    private $results = [];

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class ($this->results) implements FinishedSubscriber {
            private $results;

            public function __construct(&$results)
            {
                $this->results = &$results;
            }

            public function notify(Finished $event): void
            {
                $test = $event->test();
                $testName = $test->name();
                $status = $test->status()->isSuccess() ? 'Réussi' : 'Échoué';
                $this->results[$testName] = $status;
            }
        });

        $facade->registerSubscriber(new class ($this->results) implements \PHPUnit\Event\TestRunner\FinishedSubscriber {
            private $results;

            public function __construct(&$results)
            {
                $this->results = &$results;
            }

            public function notify(\PHPUnit\Event\TestRunner\Finished $event): void
            {
                $report = "Rapport des Tests\n=================\n\n";
                foreach ($this->results as $testName => $status) {
                    $report .= "$testName : $status\n";
                }
                file_put_contents('rapport_tests_francais.txt', $report);
            }
        });
    }
}