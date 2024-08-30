<?php
namespace TalanHdf\SemanticSuggestion\Tests\Unit\Service;

use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class PageAnalysisServiceTest extends UnitTestCase
{
    protected $pageAnalysisService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSingletonInstances = true;

        $contextMock = $this->createMock(Context::class);
        $configManagerMock = $this->createMock(ConfigurationManagerInterface::class);

        $this->pageAnalysisService = new PageAnalysisService($contextMock, $configManagerMock);
        
        // Définir les paramètres de base pour les tests
        $this->pageAnalysisService->setSettings([
            'analyzedFields' => [
                'title' => 1.5,
                'description' => 1.0,
                'keywords' => 2.0,
                'content' => 1.0
            ],
            'recencyWeight' => 0.2
        ]);
    }

    /**
     * @test
     */
    public function getWeightedWordsReturnsCorrectWeights()
    {
        $pageData = [
            'title' => ['content' => 'Test Title', 'weight' => 1.5],
            'content' => ['content' => 'This is test content', 'weight' => 1.0]
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'getWeightedWords', [$pageData]);

        $this->assertEquals(1.5, $result['test'], 'The word "test" should have a weight of 1.5 from the title');
        $this->assertEquals(2.5, $result['title'], 'The word "title" should have a weight of 1.5 from the title');
        $this->assertEquals(1.0, $result['content'], 'The word "content" should have a weight of 1.0 from the content');
    }

    /**
     * @test
     */
    public function calculateSimilarityReturnsExpectedValuesForSimilarPages()
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is a powerful CMS for web development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Web Development with TYPO3', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 offers great features for web development', 'weight' => 1.0],
            'content_modified_at' => time() - 86400 // 1 day older
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.7, $result['semanticSimilarity'], 'Pages with similar content should have high semantic similarity');
        $this->assertLessThan(0.1, $result['recencyBoost'], 'Pages with close modification dates should have low recency boost');
        $this->assertGreaterThan(0.7, $result['finalSimilarity'], 'Overall similarity should be high for similar pages');
    }

    /**
     * @test
     */
    public function calculateSimilarityReturnsLowValuesForDissimilarPages()
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is a powerful CMS for web development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Cooking Recipes', 'weight' => 1.5],
            'content' => ['content' => 'How to make a delicious chocolate cake', 'weight' => 1.0],
            'content_modified_at' => time() - 2592000 // 30 days older
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertLessThan(0.3, $result['semanticSimilarity'], 'Pages with different content should have low semantic similarity');
        $this->assertGreaterThan(0.5, $result['recencyBoost'], 'Pages with distant modification dates should have high recency boost');
        $this->assertLessThan(0.5, $result['finalSimilarity'], 'Overall similarity should be low for dissimilar pages');
    }

    /**
     * @test
     */
    public function calculateSimilarityHandlesEmptyFields()
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => '', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => '', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 development guide', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0, $result['semanticSimilarity'], 'Similarity should be calculated even with some empty fields');
        $this->assertLessThan(1, $result['semanticSimilarity'], 'Similarity should be less than 1 with some empty fields');
    }

  /**
     * @test
     */
    public function recencyWeightAffectsFinalSimilarity()
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 guide', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 Guide', 'weight' => 1.5],
            'content' => ['content' => 'Development with TYPO3', 'weight' => 1.0],
            'content_modified_at' => time() - 2592000 // 30 days older
        ];

        // Test avec un poids de récence faible
        $this->pageAnalysisService->setSettings(['recencyWeight' => 0.1]);
        $resultLowWeight = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        // Test avec un poids de récence élevé
        $this->pageAnalysisService->setSettings(['recencyWeight' => 0.8]);
        $resultHighWeight = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan($resultHighWeight['finalSimilarity'], $resultLowWeight['finalSimilarity'], 
            'Final similarity should be higher with low recency weight for pages with different modification dates');
    }

    /**
     * @test
     */
    public function similarityCalculationWithMissingFields()
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is a powerful CMS', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 Guide', 'weight' => 1.5],
            // 'content' field is missing
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0, $result['semanticSimilarity'], 'Similarity should be calculated even with missing fields');
        $this->assertLessThan(1, $result['semanticSimilarity'], 'Similarity should be less than 1 with missing fields');
    }

    /**
     * @test
     */
    public function similarityCalculationWithDifferentFieldWeights()
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 2.0],
            'description' => ['content' => 'Guide for TYPO3 development', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is a powerful CMS for web development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Web Development', 'weight' => 2.0],
            'description' => ['content' => 'Guide for web development', 'weight' => 1.5],
            'content' => ['content' => 'Web development techniques and best practices', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.3, $result['semanticSimilarity'], 'Similarity should reflect the higher weight of title and description');
        $this->assertLessThan(0.8, $result['semanticSimilarity'], 'Similarity should not be too high due to differences in content');
    }

    /**
     * @test
     */
    public function similarityCalculationWithKeywords()
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'keywords' => ['content' => 'TYPO3, CMS, web development', 'weight' => 2.0],
            'content' => ['content' => 'TYPO3 is a powerful CMS', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Web Development with TYPO3', 'weight' => 1.5],
            'keywords' => ['content' => 'TYPO3, web development, programming', 'weight' => 2.0],
            'content' => ['content' => 'Learn web development using TYPO3', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.7, $result['semanticSimilarity'], 'Similarity should be high due to matching keywords with high weight');
    }

    /**
     * @test
     */
    public function findCommonKeywordsReturnsCorrectResults()
    {
        $page1 = [
            'keywords' => ['content' => 'TYPO3, CMS, web development, PHP', 'weight' => 2.0],
        ];
        $page2 = [
            'keywords' => ['content' => 'TYPO3, web development, JavaScript, frontend', 'weight' => 2.0],
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'findCommonKeywords', [$page1, $page2]);

        $this->assertContains('typo3', $result, 'Common keyword "TYPO3" should be found');
        $this->assertContains('web development', $result, 'Common keyword "web development" should be found');
        $this->assertNotContains('php', $result, 'Keyword "PHP" should not be in common keywords');
        $this->assertNotContains('javascript', $result, 'Keyword "JavaScript" should not be in common keywords');
    }

    /**
     * Helper method to invoke private methods
     *
     * @param object &$object    Instanced object that we will run method on
     * @param string $methodName Method name to call
     * @param array  $parameters Array of parameters to pass into method
     *
     * @return mixed Method return
     */
    private function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }

    /**
     * @test
     */
    public function determineRelevanceReturnsCorrectCategory()
    {
        $this->assertEquals('High', $this->invokeMethod($this->pageAnalysisService, 'determineRelevance', [0.8]));
        $this->assertEquals('Medium', $this->invokeMethod($this->pageAnalysisService, 'determineRelevance', [0.5]));
        $this->assertEquals('Low', $this->invokeMethod($this->pageAnalysisService, 'determineRelevance', [0.2]));
    }

    /**
     * @test
     */
    public function calculateRecencyBoostReturnsExpectedValues()
    {
        $now = time();
        $page1 = ['content_modified_at' => $now];
        $page2 = ['content_modified_at' => $now - 86400]; // 1 day ago
        $page3 = ['content_modified_at' => $now - 2592000]; // 30 days ago

        $boost1 = $this->invokeMethod($this->pageAnalysisService, 'calculateRecencyBoost', [$page1, $page2]);
        $boost2 = $this->invokeMethod($this->pageAnalysisService, 'calculateRecencyBoost', [$page1, $page3]);

        $this->assertLessThan($boost2, $boost1, 'Recency boost should be lower for pages with closer modification dates');
        $this->assertGreaterThan(0, $boost1, 'Recency boost should be greater than 0');
        $this->assertLessThanOrEqual(1, $boost2, 'Recency boost should be less than or equal to 1');
    }

    /**
     * @test
     */
    public function calculateFieldSimilarityHandlesEmptyFields()
    {
        $field1 = ['content' => 'TYPO3 Development'];
        $field2 = ['content' => ''];
        $field3 = [];

        $similarity1 = $this->invokeMethod($this->pageAnalysisService, 'calculateFieldSimilarity', [$field1, $field2]);
        $similarity2 = $this->invokeMethod($this->pageAnalysisService, 'calculateFieldSimilarity', [$field1, $field3]);

        $this->assertEquals(0.0, $similarity1, 'Similarity with empty content should be 0');
        $this->assertEquals(0.0, $similarity2, 'Similarity with missing content key should be 0');
    }

    /**
     * @test
     */
    public function analyzePagesShouldHandleEmptyInput()
    {
        $result = $this->pageAnalysisService->analyzePages([], 0);

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertEmpty($result['results']);
        $this->assertEquals(0, $result['metrics']['totalPages']);
    }

    /**
     * @test
     */
    public function preparePageDataHandlesAllConfiguredFields()
    {
        $page = [
            'uid' => 1,
            'title' => 'Test Page',
            'description' => 'This is a test page',
            'keywords' => 'test, page, TYPO3',
            'abstract' => 'A brief abstract',
            'content' => 'Detailed content goes here'
        ];

        $preparedData = $this->invokeMethod($this->pageAnalysisService, 'preparePageData', [$page, 0]);

        $this->assertArrayHasKey('title', $preparedData);
        $this->assertArrayHasKey('description', $preparedData);
        $this->assertArrayHasKey('keywords', $preparedData);
        $this->assertArrayHasKey('abstract', $preparedData);
        $this->assertArrayHasKey('content', $preparedData);

        foreach (['title', 'description', 'keywords', 'abstract', 'content'] as $field) {
            $this->assertArrayHasKey('content', $preparedData[$field]);
            $this->assertArrayHasKey('weight', $preparedData[$field]);
        }
    }

    /**
     * @test
     */
    public function similarityCalculationWithLargeContentDifference()
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => str_repeat('TYPO3 is a powerful CMS for web development. ', 100), 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Web Development', 'weight' => 1.5],
            'content' => ['content' => str_repeat('Web development involves creating websites and web applications. ', 100), 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertLessThan(0.5, $result['semanticSimilarity'], 'Similarity should be low for pages with large content difference');
    }

    /**
     * @test
     */
    public function similarityCalculationWithIdenticalContent()
    {
        $content = 'TYPO3 is a powerful CMS for web development.';
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => $content, 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => $content, 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertEquals(1.0, $result['semanticSimilarity'], 'Similarity should be 1.0 for identical pages');
    }


}