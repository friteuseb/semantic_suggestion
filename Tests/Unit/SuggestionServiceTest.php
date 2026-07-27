<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Tests\Unit;

use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TalanHdf\SemanticSuggestion\Service\SuggestionService;
use TalanHdf\SemanticSuggestion\Service\UtilityService;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Tests for SuggestionService
 */
class SuggestionServiceTest extends UnitTestCase
{
    private SuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks for dependencies
        $pageAnalysisService = $this->createMock(PageAnalysisService::class);
        $fileRepository = $this->createMock(FileRepository::class);
        $pageRepository = $this->createMock(PageRepository::class);
        $utility = $this->createMock(UtilityService::class);
        $configManager = $this->createMock(ConfigurationManagerInterface::class);

        $this->service = new SuggestionService(
            $pageAnalysisService,
            $fileRepository,
            $pageRepository,
            $utility,
            $configManager
        );
    }

    /**
     * Test getQualityLevel with qualityLevel setting
     */
    public function testGetQualityLevelWithQualityLevel(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getQualityLevel');
        $method->setAccessible(true);

        $settings = ['qualityLevel' => 0.5];
        $result = $method->invoke($this->service, $settings);

        $this->assertEquals(0.5, $result);
    }

    /**
     * Test getQualityLevel with legacy proximityThreshold
     */
    public function testGetQualityLevelWithLegacyThreshold(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getQualityLevel');
        $method->setAccessible(true);

        $settings = ['proximityThreshold' => 0.6];
        $result = $method->invoke($this->service, $settings);

        $this->assertEquals(0.6, $result);
    }

    /**
     * Test getQualityLevel default value
     */
    public function testGetQualityLevelDefault(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getQualityLevel');
        $method->setAccessible(true);

        $settings = [];
        $result = $method->invoke($this->service, $settings);

        $this->assertEquals(SuggestionService::DEFAULT_QUALITY_LEVEL, $result);
    }

    /**
     * Test calculateRecencyScore
     */
    public function testCalculateRecencyScore(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateRecencyScore');
        $method->setAccessible(true);

        $timestamp = time() - (10 * 24 * 60 * 60); // 10 days ago
        $result = $method->invoke($this->service, $timestamp);

        $this->assertIsFloat($result);
        $this->assertGreaterThan(0, $result);
        $this->assertLessThanOrEqual(1, $result);
    }
}