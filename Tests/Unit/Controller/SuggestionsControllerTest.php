<?php
namespace TalanHdf\SemanticSuggestion\Tests\Unit\Controller;

use TalanHdf\SemanticSuggestion\Controller\SuggestionsController;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SuggestionsControllerTest extends UnitTestCase
{
    protected $suggestionsController;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suggestionsController = new SuggestionsController(
            $this->createMock(PageAnalysisService::class),
            $this->createMock(FileRepository::class)
        );
    }

    /**
     * @test
     */
    public function listActionShouldReturnPaginatedResults()
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['currentPage' => 1, 'itemsPerPage' => 10]);

        $response = $this->suggestionsController->listAction($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        // Add more assertions based on the expected structure of the response
    }

    /**
     * @test
     */
    public function generateSuggestionsShouldReturnCorrectStructure()
    {
        $result = $this->invokeMethod($this->suggestionsController, 'generateSuggestions', [1, 1, 10]);

        $this->assertArrayHasKey('currentPageTitle', $result);
        $this->assertArrayHasKey('suggestions', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('analysisResults', $result);
    }

    /**
     * @test
     */
    public function prepareExcerptShouldTruncateCorrectly()
    {
        $pageData = [
            'bodytext' => 'This is a long text that should be truncated to create an excerpt.'
        ];
        $excerpt = $this->invokeMethod($this->suggestionsController, 'prepareExcerpt', [$pageData, 20]);

        $this->assertEquals('This is a long text...', $excerpt);
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