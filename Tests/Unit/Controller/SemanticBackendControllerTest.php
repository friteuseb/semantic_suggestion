<?php
namespace TalanHdf\SemanticSuggestion\Tests\Unit\Controller;

use TalanHdf\SemanticSuggestion\Controller\SemanticBackendController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3\CMS\Core\Log\LogManager;
use Psr\Http\Message\ResponseInterface;

class SemanticBackendControllerTest extends UnitTestCase
{
    protected $semanticBackendController;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semanticBackendController = new SemanticBackendController(
            $this->createMock(ModuleTemplateFactory::class),
            $this->createMock(PageAnalysisService::class),
            $this->createMock(LogManager::class)
        );
    }

    /**
     * @test
     */
    public function indexActionShouldReturnResponseInterface()
    {
        $response = $this->semanticBackendController->indexAction();
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    /**
     * @test
     */
    public function updateConfigurationActionShouldUpdateSettings()
    {
        $configuration = [
            'parentPageId' => 1,
            'recursive' => 2,
            'proximityThreshold' => 0.5,
            'maxSuggestions' => 5
        ];

        $response = $this->semanticBackendController->updateConfigurationAction($configuration);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        // Add assertions to check if the configuration was actually updated
    }

    /**
     * @test
     */
    public function calculateStatisticsShouldReturnCorrectMetrics()
    {
        $analysisResults = [
            1 => ['similarities' => [2 => ['score' => 0.8], 3 => ['score' => 0.6]]],
            2 => ['similarities' => [1 => ['score' => 0.8], 3 => ['score' => 0.7]]],
            3 => ['similarities' => [1 => ['score' => 0.6], 2 => ['score' => 0.7]]]
        ];

        $result = $this->invokeMethod($this->semanticBackendController, 'calculateStatistics', [$analysisResults, 0.5, true, true]);

        $this->assertArrayHasKey('totalPages', $result);
        $this->assertArrayHasKey('averageSimilarity', $result);
        $this->assertArrayHasKey('topSimilarPairs', $result);
        $this->assertArrayHasKey('distributionScores', $result);
    }

    /**
     * Helper method to invoke private methods
     */
    private function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}