<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Tests\Unit;

use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TalanHdf\SemanticSuggestion\Task\GenerateSimilaritiesTask;
use TalanHdf\SemanticSuggestion\Service\SuggestionService;
use TalanHdf\SemanticSuggestion\Service\UtilityService;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Tests for unified configuration logic
 */
class UnifiedConfigurationTest extends UnitTestCase
{
    /**
     * Test that qualityLevel properly computes minimumSimilarity
     */
    public function testQualityLevelComputesMinimumSimilarity(): void
    {
        $task = new GenerateSimilaritiesTask();

        // Test default values
        $task->qualityLevel = 0.3;
        $task->initializeQualityLevel();

        $this->assertEquals(0.3, $task->minimumSimilarity, 'Storage threshold should equal qualityLevel');
    }

    /**
     * Test legacy migration from minimumSimilarity to qualityLevel
     */
    public function testLegacyMigrationFromMinimumSimilarity(): void
    {
        $task = new GenerateSimilaritiesTask();

        // Simulate legacy configuration
        $task->qualityLevel = 0.3; // default
        $task->minimumSimilarity = 0.5; // legacy customized value

        $task->initializeQualityLevel();

        // Should migrate to qualityLevel = minimumSimilarity (direct mapping)
        $this->assertEquals(0.5, $task->qualityLevel, 'Should migrate legacy minimumSimilarity to qualityLevel');
        $this->assertEquals(0.5, $task->minimumSimilarity, 'minimumSimilarity should equal qualityLevel');
    }

    /**
     * Test edge cases for quality level bounds
     */
    public function testQualityLevelBounds(): void
    {
        $task = new GenerateSimilaritiesTask();

        // No offset: the storage threshold is the quality level itself
        $task->qualityLevel = 0.1;
        $task->initializeQualityLevel();
        $this->assertEquals(0.1, $task->minimumSimilarity, 'Storage should equal quality level');

        // Below the floor, the floor wins
        $task->qualityLevel = 0.01;
        $task->initializeQualityLevel();
        $this->assertEquals(
            GenerateSimilaritiesTask::MINIMUM_STORAGE_THRESHOLD,
            $task->minimumSimilarity,
            'Minimum storage threshold should be 0.05'
        );

        // Test high quality level
        $task->qualityLevel = 0.9;
        $task->initializeQualityLevel();
        $this->assertEquals(0.9, $task->minimumSimilarity, 'Storage should equal quality level');
    }

    /**
     * Test SuggestionService backward compatibility
     */
    public function testSuggestionServiceBackwardCompatibility(): void
    {
        // Create mocks for dependencies
        $pageAnalysisService = $this->createMock(PageAnalysisService::class);
        $fileRepository = $this->createMock(FileRepository::class);
        $pageRepository = $this->createMock(PageRepository::class);
        $utility = $this->createMock(UtilityService::class);
        $configManager = $this->createMock(ConfigurationManagerInterface::class);

        $service = new SuggestionService(
            $pageAnalysisService,
            $fileRepository,
            $pageRepository,
            $utility,
            $configManager
        );

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('getQualityLevel');
        $method->setAccessible(true);

        // Test new qualityLevel parameter
        $settings1 = ['qualityLevel' => 0.4];
        $this->assertEquals(0.4, $method->invoke($service, $settings1));

        // Test legacy proximityThreshold fallback
        $settings2 = ['proximityThreshold' => 0.5];
        $this->assertEquals(0.5, $method->invoke($service, $settings2));

        // Test default fallback
        $settings3 = [];
        $this->assertEquals(0.3, $method->invoke($service, $settings3));

        // Test priority: qualityLevel wins over proximityThreshold
        $settings4 = ['qualityLevel' => 0.4, 'proximityThreshold' => 0.5];
        $this->assertEquals(0.4, $method->invoke($service, $settings4));
    }

    /**
     * Test configuration migration scenarios
     */
    public function testConfigurationMigrationScenarios(): void
    {
        $task = new GenerateSimilaritiesTask();

        // Scenario 1: Fresh installation (all defaults)
        $task->qualityLevel = 0.3;
        $task->minimumSimilarity = 0.1;
        $task->initializeQualityLevel();

        $this->assertEquals(0.3, $task->qualityLevel);
        $this->assertEquals(0.3, $task->minimumSimilarity, 'Should sync with qualityLevel');

        // Scenario 2: Upgrade from v2.x (only minimumSimilarity customized)
        $task2 = new GenerateSimilaritiesTask();
        $task2->qualityLevel = 0.3; // default
        $task2->minimumSimilarity = 0.4; // legacy custom
        $task2->initializeQualityLevel();

        $this->assertEquals(0.4, $task2->qualityLevel, 'Should migrate to qualityLevel');
        $this->assertEquals(0.4, $task2->minimumSimilarity, 'Should sync with qualityLevel');
    }
}