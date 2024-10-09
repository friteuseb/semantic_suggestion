<?php
namespace TalanHdf\SemanticSuggestion\Tests\Unit\Service;

use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TalanHdf\SemanticSuggestion\Service\NlpApiService;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class ApiTest extends UnitTestCase
{
    protected NlpApiService $nlpApiService;
    protected $requestFactory;
    protected $extensionConfiguration;
    protected $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->extensionConfiguration->method('get')
            ->with('semantic_suggestion')
            ->willReturn([
                'settings' => ['nlpApiUrl' => 'https://nlpservice.semantic-suggestion.com/api']
            ]);

        $this->nlpApiService = new NlpApiService(
            $this->requestFactory,
            $this->extensionConfiguration
        );
        $this->nlpApiService->setLogger($this->logger);
    }

    /**
     * @test
     */
    public function addTextsWithRealExamplesReturnsExpectedResult(): void
    {
        $texts = [
            ['id' => '1', 'text' => 'TYPO3 is a free and open-source web content management system written in PHP.'],
            ['id' => '2', 'text' => 'PHP is a popular general-purpose scripting language that is especially suited to web development.'],
            ['id' => '3', 'text' => 'Web content management systems are used to manage and simplify the publication of web content.']
        ];

        $expectedResponse = [
            'message' => 'Added/Updated 3 texts',
            'details' => [
                'added' => 3,
                'updated' => 0,
                'failed' => 0
            ]
        ];

        $this->setupMockResponse(json_encode($expectedResponse), 200);

        $result = $this->nlpApiService->addTexts($texts);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('details', $result);
        $this->assertEquals('Added/Updated 3 texts', $result['message']);
        $this->assertEquals(3, $result['details']['added']);
        $this->assertEquals(0, $result['details']['updated']);
        $this->assertEquals(0, $result['details']['failed']);
    }

    /**
     * @test
     */
    public function findSimilarWithRealExamplesReturnsExpectedResult(): void
    {
        $queryId = '1';
        $queryText = 'TYPO3 is a content management system';
        $k = 2;

        $expectedResponse = [
            ['id' => '1', 'similarity' => 0.95, 'text' => 'TYPO3 is a free and open-source web content management system written in PHP.'],
            ['id' => '3', 'similarity' => 0.75, 'text' => 'Web content management systems are used to manage and simplify the publication of web content.']
        ];

        $this->setupMockResponse(json_encode($expectedResponse), 200);

        $result = $this->nlpApiService->findSimilar($queryId, $queryText, $k);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('1', $result[0]['id']);
        $this->assertGreaterThanOrEqual(0.9, $result[0]['similarity']);
        $this->assertEquals('3', $result[1]['id']);
        $this->assertGreaterThanOrEqual(0.7, $result[1]['similarity']);
        $this->assertStringContainsString('content management system', $result[0]['text']);
        $this->assertStringContainsString('content management systems', $result[1]['text']);
    }

    /**
     * @test
     */
    public function apiHandlesLargeNumberOfTextsCorrectly(): void
    {
        $texts = [];
        for ($i = 1; $i <= 1000; $i++) {
            $texts[] = ['id' => (string)$i, 'text' => "This is test text number $i for large scale testing."];
        }

        $expectedResponse = [
            'message' => 'Added/Updated 1000 texts',
            'details' => [
                'added' => 1000,
                'updated' => 0,
                'failed' => 0
            ]
        ];

        $this->setupMockResponse(json_encode($expectedResponse), 200);

        $result = $this->nlpApiService->addTexts($texts);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('details', $result);
        $this->assertEquals('Added/Updated 1000 texts', $result['message']);
        $this->assertEquals(1000, $result['details']['added']);
    }

    /**
     * @test
     */
    public function apiHandlesSpecialCharactersAndMultilingualContentCorrectly(): void
    {
        $texts = [
            ['id' => '1', 'text' => 'L\'été est chaud à Marseille. J\'aime les crêpes !'],
            ['id' => '2', 'text' => 'Ich bin ein Berliner. Schönes Wetter heute!'],
            ['id' => '3', 'text' => '¡Hola! ¿Cómo estás? Me gusta la paella.']
        ];

        $expectedResponse = [
            'message' => 'Added/Updated 3 texts',
            'details' => [
                'added' => 3,
                'updated' => 0,
                'failed' => 0
            ]
        ];

        $this->setupMockResponse(json_encode($expectedResponse), 200);

        $result = $this->nlpApiService->addTexts($texts);

        $this->assertIsArray($result);
        $this->assertEquals('Added/Updated 3 texts', $result['message']);
        $this->assertEquals(3, $result['details']['added']);

        $queryId = '1';
        $queryText = 'J\'aime l\'été en France';
        $k = 3;
    
        $expectedSimilarResponse = [
            ['id' => '1', 'similarity' => 0.8, 'text' => 'L\'été est chaud à Marseille. J\'aime les crêpes !'],
            ['id' => '2', 'similarity' => 0.4, 'text' => 'Ich bin ein Berliner. Schönes Wetter heute!'],
            ['id' => '3', 'similarity' => 0.2, 'text' => '¡Hola! ¿Cómo estás? Me gusta la paella.']
        ];
    
        $this->setupMockResponse(json_encode($expectedSimilarResponse), 200);
    
        $similarResult = $this->nlpApiService->findSimilar($queryId, $queryText, $k);

        $this->assertNotNull($similarResult, "The result should not be null");
        
        if ($similarResult !== null) {
            $this->assertIsArray($similarResult, "The result should be an array");
            $this->assertNotEmpty($similarResult, "The result should not be empty");
            
            $this->assertArrayHasKey('id', $similarResult[0], "The first result should have an 'id' key");
            $this->assertArrayHasKey('similarity', $similarResult[0], "The first result should have a 'similarity' key");
            $this->assertArrayHasKey('text', $similarResult[0], "The first result should have a 'text' key");
            
            $this->assertEquals('1', $similarResult[0]['id']);
            $this->assertGreaterThanOrEqual(0.7, $similarResult[0]['similarity']);
            $this->assertStringContainsString('été', $similarResult[0]['text']);
            $this->assertStringContainsString('J\'aime', $similarResult[0]['text']);
        }
    }

    /**
     * @test
     */
    public function apiHandlesEdgeCasesCorrectly(): void
    {
        // Test with empty text
        $emptyText = ['id' => '1', 'text' => ''];
        $this->setupMockResponse(json_encode(['error' => 'Text cannot be empty']), 400);
        $result = $this->nlpApiService->addTexts([$emptyText]);
        $this->assertArrayHasKey('error', $result);

        // Test with very long text
        $longText = ['id' => '2', 'text' => str_repeat('a', 1000000)];
        $this->setupMockResponse(json_encode(['error' => 'Text exceeds maximum length']), 400);
        $result = $this->nlpApiService->addTexts([$longText]);
        $this->assertArrayHasKey('error', $result);

        // Test with invalid ID
        $invalidId = ['id' => '', 'text' => 'Valid text'];
        $this->setupMockResponse(json_encode(['error' => 'Invalid ID']), 400);
        $result = $this->nlpApiService->addTexts([$invalidId]);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function apiHandlesErrorsGracefully(): void
    {
        // Test API unavailable
        $this->setupMockResponse('', 503);
        $result = $this->nlpApiService->addTexts([['id' => '1', 'text' => 'Test']]);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Service Unavailable', $result['error']);

        // Test unauthorized access
        $this->setupMockResponse(json_encode(['error' => 'Unauthorized']), 401);
        $result = $this->nlpApiService->getStatus();
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Unauthorized', $result['error']);

        // Test rate limiting
        $this->setupMockResponse(json_encode(['error' => 'Rate limit exceeded']), 429);
        $result = $this->nlpApiService->findSimilar('1', 'Test', 5);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Rate limit exceeded', $result['error']);
    }

    protected function setupMockResponse(string $body, int $statusCode): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        $this->requestFactory
            ->method('request')
            ->willReturn($response);
    }
}