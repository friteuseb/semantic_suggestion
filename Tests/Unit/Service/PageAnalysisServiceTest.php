<?php
namespace TalanHdf\SemanticSuggestion\Tests\Unit\Service;

use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TalanHdf\SemanticSuggestion\Service\StopWordsService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class PageAnalysisServiceTest extends UnitTestCase
{
    protected $pageAnalysisService;
    protected $stopWordsServiceMock;
    protected $contextMock;
    protected $siteLanguageMock;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->siteLanguageMock = $this->createMock(SiteLanguage::class);
        $this->siteLanguageMock->method('getLocale')->willReturn('en_US.UTF-8'); // Default to English

        $this->contextMock = $this->createMock(Context::class);
        $this->contextMock->method('getAspect')->with('language')->willReturn($this->siteLanguageMock);

        $configManagerMock = $this->createMock(ConfigurationManagerInterface::class);
        $this->stopWordsServiceMock = $this->createMock(StopWordsService::class);

        $this->pageAnalysisService = new PageAnalysisService(
            $this->contextMock, 
            $configManagerMock, 
            null, 
            $this->stopWordsServiceMock
        );
    }

    
    /**
     * @test
     */
    public function correctLanguageIsDetectedForStopWordsRemoval()
    {
        $this->siteLanguageMock->method('getLocale')->willReturn('fr_FR.UTF-8'); // Set to French


        $this->contextMock->method('getAspect')->with('language')->willReturn($languageAspectMock);

        $this->stopWordsServiceMock->expects($this->once())
            ->method('removeStopWords')
            ->with($this->anything(), 'fr') // Expecting French language code
            ->willReturn('content sans mots vides');

        $pageData = [
            'title' => ['content' => 'Le titre en français', 'weight' => 1.5],
            'content' => ['content' => 'Le contenu en français avec des mots vides', 'weight' => 1.0],
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'preparePageData', [$pageData, 1]);

        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
    }

    /**
     * @test
     */
    public function stopWordsAreCorrectlyRemovedForDifferentLanguages()
    {
        $languages = [
            'en' => 'en_US.UTF-8',
            'fr' => 'fr_FR.UTF-8',
            'de' => 'de_DE.UTF-8'
        ];

        foreach ($languages as $langCode => $langId) {
            $languageAspectMock = $this->createMock(LanguageAspect::class);
            $languageAspectMock->method('getLanguageId')->willReturn($langId);

            $this->contextMock->method('getAspect')->with('language')->willReturn($languageAspectMock);

            $this->stopWordsServiceMock->expects($this->once())
                ->method('removeStopWords')
                ->with($this->anything(), $langCode)
                ->willReturn("content without stopwords for {$langCode}");

            $pageData = [
                'title' => ['content' => "Title in {$langCode}", 'weight' => 1.5],
                'content' => ['content' => "Content with stopwords in {$langCode}", 'weight' => 1.0],
            ];

            $result = $this->invokeMethod($this->pageAnalysisService, 'preparePageData', [$pageData, $langId]);

            $this->assertEquals("content without stopwords for {$langCode}", $result['content']['content']);
        }
    }


        /**
         * @test
         */
        public function stopWordsServiceUsesCorrectLanguageFile()
        {
            $stopWordsService = new StopWordsService();
            $availableLanguages = $stopWordsService->getAvailableLanguages();

            $this->assertContains('en', $availableLanguages, 'English stopwords should be available');
            $this->assertContains('fr', $availableLanguages, 'French stopwords should be available');
            $this->assertContains('de', $availableLanguages, 'German stopwords should be available');

            // Test removal of stopwords for each language
            $testCases = [
                'en' => ['input' => 'This is a test sentence', 'expected' => 'test sentence'],
                'fr' => ['input' => 'Ceci est une phrase de test', 'expected' => 'phrase test'],
                'de' => ['input' => 'Dies ist ein Testsatz', 'expected' => 'Testsatz'],
            ];

            foreach ($testCases as $lang => $case) {
                $result = $stopWordsService->removeStopWords($case['input'], $lang);
                $this->assertEquals($case['expected'], $result, "Stopwords not correctly removed for {$lang}");
            }
        }



        /**
         * @test
         */
        public function stopWordsAreRemovedBeforeSimilarityCalculation()
        {
            $page1 = [
                'title' => ['content' => 'The TYPO3 CMS', 'weight' => 1.5],
                'content' => ['content' => 'TYPO3 is a content management system', 'weight' => 1.0],
                'content_modified_at' => time()
            ];
            $page2 = [
                'title' => ['content' => 'A Web Development Framework', 'weight' => 1.5],
                'content' => ['content' => 'TYPO3 can be used for web development', 'weight' => 1.0],
                'content_modified_at' => time()
            ];

            $this->stopWordsServiceMock->expects($this->exactly(4))
                ->method('removeStopWords')
                ->willReturnOnConsecutiveCalls(
                    'typo3 cms',
                    'typo3 content management system',
                    'web development framework',
                    'typo3 used web development'
                );

            $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

            $this->assertGreaterThan(0.5, $result['semanticSimilarity'], 'Similarity should be significant even after stop words removal');
            $this->assertLessThan(1.0, $result['semanticSimilarity'], 'Similarity should not be perfect due to different content');
        }

   

    /**
     * @test
     */
    public function similarityCalculationHandlesMultilingualContentWithStopWords()
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'Information about TYPO3 development', 'weight' => 1.0],
            'sys_language_uid' => 0,
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Développement TYPO3', 'weight' => 1.5],
            'content' => ['content' => 'Informations sur le développement TYPO3', 'weight' => 1.0],
            'sys_language_uid' => 1,
            'content_modified_at' => time()
        ];

        $this->stopWordsServiceMock->expects($this->exactly(4))
            ->method('removeStopWords')
            ->withConsecutive(
                ['TYPO3 Development', 'en'],
                ['Information about TYPO3 development', 'en'],
                ['Développement TYPO3', 'fr'],
                ['Informations sur le développement TYPO3', 'fr']
            )
            ->willReturnOnConsecutiveCalls(
                'typo3 development',
                'information typo3 development',
                'développement typo3',
                'informations développement typo3'
            );

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.5, $result['semanticSimilarity'], 
            'Similarity should be detected even for content in different languages after stop words removal');
        $this->assertLessThan(0.9, $result['semanticSimilarity'], 
            'Similarity should not be too high for content in different languages');
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
    public function getWeightedWordsReturnsCorrectWeightsWithStopWordsRemoved()
    {
        $pageData = [
            'title' => ['content' => 'The Test Title', 'weight' => 1.5],
            'content' => ['content' => 'This is a test content', 'weight' => 1.0]
        ];

        $this->stopWordsServiceMock->expects($this->exactly(2))
            ->method('removeStopWords')
            ->withConsecutive(
                ['The Test Title', 'en'],
                ['This is a test content', 'en']
            )
            ->willReturnOnConsecutiveCalls(
                'test title',
                'test content'
            );

        $result = $this->invokeMethod($this->pageAnalysisService, 'getWeightedWords', [$pageData]);

        $this->assertEquals(1.5, $result['test'], 'The word "test" should have a weight of 1.5 from the title');
        $this->assertEquals(2.5, $result['title'], 'The word "title" should have a weight of 1.5 from the title');
        $this->assertEquals(1.0, $result['content'], 'The word "content" should have a weight of 1.0 from the content');
        $this->assertArrayNotHasKey('the', $result, 'Stop word "the" should be removed');
        $this->assertArrayNotHasKey('is', $result, 'Stop word "is" should be removed');
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
    public function calculateSimilarityReturnsExpectedValuesForSimilarPagesWithStopWordsRemoved()
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

        $this->stopWordsServiceMock->expects($this->exactly(4))
            ->method('removeStopWords')
            ->withConsecutive(
                ['TYPO3 Development', 'en'],
                ['TYPO3 is a powerful CMS for web development', 'en'],
                ['Web Development with TYPO3', 'en'],
                ['TYPO3 offers great features for web development', 'en']
            )
            ->willReturnOnConsecutiveCalls(
                'typo3 development',
                'typo3 powerful cms web development',
                'web development typo3',
                'typo3 offers great features web development'
            );

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
     * @test
     */
    public function findCommonKeywordsReturnsCorrectResultsWithStopWordsRemoved()
    {
        $page1 = [
            'keywords' => ['content' => 'TYPO3, CMS, web development, PHP', 'weight' => 2.0],
        ];
        $page2 = [
            'keywords' => ['content' => 'TYPO3, web development, JavaScript, frontend', 'weight' => 2.0],
        ];

        $this->stopWordsServiceMock->expects($this->exactly(2))
            ->method('removeStopWords')
            ->withConsecutive(
                ['TYPO3, CMS, web development, PHP', 'en'],
                ['TYPO3, web development, JavaScript, frontend', 'en']
            )
            ->willReturnOnConsecutiveCalls(
                'typo3 cms web development php',
                'typo3 web development javascript frontend'
            );

        $result = $this->invokeMethod($this->pageAnalysisService, 'findCommonKeywords', [$page1, $page2]);

        $this->assertContains('typo3', $result, 'Common keyword "TYPO3" should be found');
        $this->assertContains('web development', $result, 'Common keyword "web development" should be found');
        $this->assertNotContains('php', $result, 'Keyword "PHP" should not be in common keywords');
        $this->assertNotContains('javascript', $result, 'Keyword "JavaScript" should not be in common keywords');
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


    /**
     * @test
     */
    public function identicalContentShouldHaveMaximumSimilarity1(): void
    {
        $page1 = $page2 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'This is a test page about TYPO3 development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertEquals(1.0, $result['semanticSimilarity'], 'Des pages identiques devraient avoir une similarité sémantique de 1.0');
        $this->assertEquals(1.0, $result['finalSimilarity'], 'Des pages identiques devraient avoir une similarité finale de 1.0');
    }

    /**
     * @test
     */
    public function completelyDifferentContentShouldHaveMinimumSimilarity2(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'This is about web development with TYPO3', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Cooking Recipes', 'weight' => 1.5],
            'content' => ['content' => 'How to make a delicious chocolate cake', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertLessThan(0.1, $result['semanticSimilarity'], 'Des pages complètement différentes devraient avoir une similarité sémantique proche de 0');
        $this->assertLessThan(0.1, $result['finalSimilarity'], 'Des pages complètement différentes devraient avoir une similarité finale proche de 0');
    }

    /**
     * @test
     */
    public function partiallyRelatedContentShouldHaveModerateSimilarity(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'This is about web development with TYPO3', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Web Development Tools', 'weight' => 1.5],
            'content' => ['content' => 'Various tools used in modern web development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.3, $result['semanticSimilarity'], 'Des pages partiellement liées devraient avoir une similarité sémantique modérée');
        $this->assertLessThan(0.7, $result['semanticSimilarity'], 'Des pages partiellement liées ne devraient pas avoir une similarité sémantique trop élevée');
    }

    /**
     * @test
     */
    public function keywordsShouldHaveSignificantImpactOnSimilarity(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Guide', 'weight' => 1.5],
            'keywords' => ['content' => 'TYPO3, CMS, web development', 'weight' => 2.0],
            'content' => ['content' => 'A brief guide about TYPO3', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Web Development with TYPO3', 'weight' => 1.5],
            'keywords' => ['content' => 'TYPO3, web development, CMS', 'weight' => 2.0],
            'content' => ['content' => 'Learn web development using TYPO3 CMS', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.7, $result['semanticSimilarity'], 'Des pages avec des mots-clés très similaires devraient avoir une forte similarité sémantique');
    }

      /**
     * @test
     */
    public function recencyWeightShouldAffectFinalSimilarity(): void
    {
        $recentPage = [
            'title' => ['content' => 'TYPO3 News', 'weight' => 1.5],
            'content' => ['content' => 'Recent updates in TYPO3', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $oldPage = [
            'title' => ['content' => 'TYPO3 History', 'weight' => 1.5],
            'content' => ['content' => 'The history of TYPO3 CMS', 'weight' => 1.0],
            'content_modified_at' => time() - (365 * 24 * 60 * 60) // 1 year old
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$recentPage, $oldPage]);

        $this->assertGreaterThan($result['semanticSimilarity'], $result['finalSimilarity'], 
            'La similarité finale devrait être influencée par la récence');
        $this->assertLessThan(1, $result['finalSimilarity'], 
            'La similarité finale ne devrait pas atteindre 1 pour des pages avec des dates très différentes');
    }

    /**
     * @test
     */
    public function shortContentShouldNotSkewSimilarityCalculation(): void
    {
        $shortPage = [
            'title' => ['content' => 'TYPO3', 'weight' => 1.5],
            'content' => ['content' => 'CMS', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $normalPage = [
            'title' => ['content' => 'TYPO3 Content Management', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is a powerful and flexible content management system', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$shortPage, $normalPage]);

        $this->assertGreaterThan(0, $result['semanticSimilarity'], 
            'Même avec un contenu court, la similarité ne devrait pas être nulle');
        $this->assertLessThan(0.8, $result['semanticSimilarity'], 
            'La similarité ne devrait pas être trop élevée juste parce qu\'un contenu est court');
    }

    /**
     * @test
     */
    public function fieldWeightsShouldInfluenceSimilarityCalculation(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'keywords' => ['content' => 'CMS, web', 'weight' => 2.0],
            'content' => ['content' => 'General content about TYPO3', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Web Development', 'weight' => 1.5],
            'keywords' => ['content' => 'CMS, web', 'weight' => 2.0],
            'content' => ['content' => 'Content about web development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.5, $result['semanticSimilarity'], 
            'Les mots-clés identiques avec un poids élevé devraient augmenter significativement la similarité');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldHandleMissingFields(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Guide', 'weight' => 1.5],
            'content' => ['content' => 'A comprehensive guide to TYPO3', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 Tutorial', 'weight' => 1.5],
            'keywords' => ['content' => 'TYPO3, CMS, tutorial', 'weight' => 2.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0, $result['semanticSimilarity'], 
            'La similarité devrait être calculée même avec des champs manquants');
        $this->assertLessThan(0.8, $result['semanticSimilarity'], 
            'La similarité ne devrait pas être trop élevée avec des champs manquants');
    }

    /**
     * @test
     */
    public function extremelyLongContentShouldNotOverwhelmOtherFactors(): void
    {
        $normalPage = [
            'title' => ['content' => 'TYPO3 Introduction', 'weight' => 1.5],
            'content' => ['content' => 'A brief introduction to TYPO3 CMS', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $longPage = [
            'title' => ['content' => 'TYPO3 Detailed Guide', 'weight' => 1.5],
            'content' => ['content' => str_repeat('Detailed information about TYPO3 and its features. ', 1000), 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$normalPage, $longPage]);

        $this->assertGreaterThan(0.2, $result['semanticSimilarity'], 
            'Un contenu extrêmement long ne devrait pas réduire drastiquement la similarité');
        $this->assertLessThan(0.9, $result['semanticSimilarity'], 
            'Un contenu extrêmement long ne devrait pas dominer complètement le calcul de similarité');
    }


      /**
     * @test
     */
    public function stopWordsShouldNotSignificantlyInfluenceSimilarity(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'This is a page about TYPO3 development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 Development Guide', 'weight' => 1.5],
            'content' => ['content' => 'Here we have information on TYPO3 development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.7, $result['semanticSimilarity'], 
            'Les mots vides ne devraient pas réduire significativement la similarité entre des pages au contenu similaire');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldBeCaseInsensitive(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'Information about TYPO3 development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Typo3 development', 'weight' => 1.5],
            'content' => ['content' => 'Information about Typo3 Development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.9, $result['semanticSimilarity'], 
            'La similarité devrait être élevée indépendamment de la casse des mots');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldHandleMultilingualContent(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'Information about TYPO3 development', 'weight' => 1.0],
            'sys_language_uid' => 0,
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Développement TYPO3', 'weight' => 1.5],
            'content' => ['content' => 'Informations sur le développement TYPO3', 'weight' => 1.0],
            'sys_language_uid' => 1,
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.3, $result['semanticSimilarity'], 
            'La similarité devrait être détectée même pour du contenu dans différentes langues');
        $this->assertLessThan(0.7, $result['semanticSimilarity'], 
            'La similarité ne devrait pas être trop élevée pour du contenu dans différentes langues');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldHandleSpecialCharacters(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 & Web Development', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is great for web-development!', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 and Web Development', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is excellent for web development.', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.8, $result['semanticSimilarity'], 
            'Les caractères spéciaux ne devraient pas affecter significativement le calcul de similarité');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldHandleNumericalContent(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 version 10', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 v10 was released in 2020', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 10.4 LTS', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 version 10.4 LTS was released in 2020', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.7, $result['semanticSimilarity'], 
            'Le contenu numérique devrait être pris en compte dans le calcul de similarité');
    }


      /**
     * @test
     */
    public function similarityCalculationShouldHandleEmptyContent(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3', 'weight' => 1.5],
            'content' => ['content' => '', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 CMS', 'weight' => 1.5],
            'content' => ['content' => 'Content Management System', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0, $result['semanticSimilarity'], 
            'La similarité ne devrait pas être nulle même avec un contenu vide');
        $this->assertLessThan(0.5, $result['semanticSimilarity'], 
            'La similarité ne devrait pas être trop élevée avec un contenu vide');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldHandleDuplicateWords(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 TYPO3 TYPO3', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is a CMS', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 Content Management', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is a content management system', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.5, $result['semanticSimilarity'], 
            'La répétition de mots ne devrait pas augmenter excessivement la similarité');
        $this->assertLessThan(0.9, $result['semanticSimilarity'], 
            'La similarité ne devrait pas être trop élevée malgré la répétition de mots');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldConsiderWordOrder(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development Guide', 'weight' => 1.5],
            'content' => ['content' => 'A guide for TYPO3 development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Development Guide for TYPO3', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 development: a comprehensive guide', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.7, $result['semanticSimilarity'], 
            'L\'ordre des mots ne devrait pas trop affecter la similarité pour un contenu similaire');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldHandleLongPhrases(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Content Management System', 'weight' => 1.5],
            'content' => ['content' => 'TYPO3 is an enterprise-class open source content management system', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'Enterprise CMS TYPO3', 'weight' => 1.5],
            'content' => ['content' => 'An open source system for managing enterprise-level content', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

        $this->assertGreaterThan(0.5, $result['semanticSimilarity'], 
            'Les phrases longues avec un sens similaire devraient avoir une similarité significative');
    }

    /**
     * @test
     */
    public function recencyBoostShouldNotOverrideSemanticallyDissimilarContent(): void
    {
        $recentPage = [
            'title' => ['content' => 'Latest TYPO3 News', 'weight' => 1.5],
            'content' => ['content' => 'Recent updates in TYPO3 community', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $oldButRelevantPage = [
            'title' => ['content' => 'TYPO3 Core Features', 'weight' => 1.5],
            'content' => ['content' => 'Fundamental features of TYPO3 CMS', 'weight' => 1.0],
            'content_modified_at' => time() - (365 * 24 * 60 * 60) // 1 year old
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$recentPage, $oldButRelevantPage]);

        $this->assertGreaterThan($result['recencyBoost'], $result['semanticSimilarity'], 
            'La similarité sémantique devrait avoir plus de poids que le boost de récence pour du contenu pertinent');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldHandleExtremeCases(): void
    {
        $emptyPage = [
            'title' => ['content' => '', 'weight' => 1.5],
            'content' => ['content' => '', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $veryLongPage = [
            'title' => ['content' => 'TYPO3', 'weight' => 1.5],
            'content' => ['content' => str_repeat('TYPO3 is a content management system. ', 1000), 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$emptyPage, $veryLongPage]);

        $this->assertEquals(0, $result['semanticSimilarity'], 
            'La similarité sémantique devrait être nulle entre une page vide et une page très longue');
        $this->assertGreaterThan(0, $result['finalSimilarity'], 
            'La similarité finale ne devrait pas être nulle à cause du facteur de récence');
    }

    /**
     * @test
     */
    public function similarityCalculationShouldBeConsistentRegardlessOfPageOrder(): void
    {
        $page1 = [
            'title' => ['content' => 'TYPO3 Development', 'weight' => 1.5],
            'content' => ['content' => 'Guide to TYPO3 development', 'weight' => 1.0],
            'content_modified_at' => time()
        ];
        $page2 = [
            'title' => ['content' => 'TYPO3 Administration', 'weight' => 1.5],
            'content' => ['content' => 'Guide to TYPO3 administration', 'weight' => 1.0],
            'content_modified_at' => time()
        ];

        $result1 = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);
        $result2 = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page2, $page1]);

        $this->assertEquals($result1['semanticSimilarity'], $result2['semanticSimilarity'], 
            'Le calcul de similarité devrait être symétrique');
    }


        /**
         * @test
         */
        public function identicalContentShouldHaveMaximumSimilarity2(): void
        {
            $this->pageAnalysisService->setSettings([
                'analyzedFields' => [
                    'title' => 0,
                    'description' => 0,
                    'keywords' => 0,
                    'abstract' => 0,
                    'content' => 1
                ],
                'recencyWeight' => 0 // Désactivons le poids de récence pour ce test
            ]);

            $page1 = [
                'content' => ['content' => 'TYPO3 est un système de gestion de contenu puissant et flexible.', 'weight' => 1],
                'content_modified_at' => time()
            ];
            $page2 = [
                'content' => ['content' => 'TYPO3 est un système de gestion de contenu puissant et flexible.', 'weight' => 1],
                'content_modified_at' => time()
            ];

            $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

            $this->assertEquals(1.0, $result['semanticSimilarity'], 'Des contenus identiques devraient avoir une similarité de 1.0');
            $this->assertEquals(1.0, $result['finalSimilarity'], 'La similarité finale devrait être 1.0 sans l effet de récence');
        }

        /**
         * @test
         */
        public function completelyDifferentContentShouldHaveMinimumSimilarity1(): void
        {
            $this->pageAnalysisService->setSettings([
                'analyzedFields' => [
                    'title' => 0,
                    'description' => 0,
                    'keywords' => 0,
                    'abstract' => 0,
                    'content' => 1
                ],
                'recencyWeight' => 0.2 // Ajoutons un peu de poids de récence
            ]);

            $page1 = [
                'content' => ['content' => 'TYPO3 est un système de gestion de contenu pour le web.', 'weight' => 1],
                'content_modified_at' => time()
            ];
            $page2 = [
                'content' => ['content' => 'PHP est un langage de programmation populaire pour le développement web.', 'weight' => 1],
                'content_modified_at' => time() - (30 * 24 * 60 * 60) // 30 jours plus ancien
            ];

            $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

            $this->assertLessThan(0.2, $result['semanticSimilarity'], 'Des contenus complètement différents devraient avoir une similarité proche de 0');
            $this->assertGreaterThan($result['semanticSimilarity'], $result['finalSimilarity'], 'La similarité finale devrait être légèrement plus élevée en raison de l effet de récence');
        }

        /**
         * @test
         */
        public function partiallyRelatedContentShouldHaveModerateSimilarityOnlyContent(): void
        {
            $this->pageAnalysisService->setSettings([
                'analyzedFields' => [
                    'title' => 0,
                    'description' => 0,
                    'keywords' => 0,
                    'abstract' => 0,
                    'content' => 1
                ],
                'recencyWeight' => 0.1 // Un faible poids de récence
            ]);

            $page1 = [
                'content' => ['content' => 'TYPO3 est un CMS open source puissant pour la création de sites web.', 'weight' => 1],
                'content_modified_at' => time()
            ];
            $page2 = [
                'content' => ['content' => 'WordPress est un autre CMS populaire pour la gestion de contenu en ligne.', 'weight' => 1],
                'content_modified_at' => time() - (7 * 24 * 60 * 60) // 7 jours plus ancien
            ];

            $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

            $this->assertGreaterThan(0.3, $result['semanticSimilarity'], 'Des contenus partiellement liés devraient avoir une similarité modérée');
            $this->assertLessThan(0.7, $result['semanticSimilarity'], 'La similarité ne devrait pas être trop élevée pour des contenus partiellement liés');
            $this->assertGreaterThanOrEqual($result['semanticSimilarity'], $result['finalSimilarity'], 'La similarité finale devrait être légèrement plus élevée ou égale à la similarité sémantique');
        }

        /**
         * @test
         */
        public function similarContentWithDifferentWordingShouldHaveHighSimilarity(): void
        {
            $this->pageAnalysisService->setSettings([
                'analyzedFields' => [
                    'title' => 0,
                    'description' => 0,
                    'keywords' => 0,
                    'abstract' => 0,
                    'content' => 1
                ],
                'recencyWeight' => 0.5 // Un poids de récence élevé pour tester son impact
            ]);

            $page1 = [
                'content' => ['content' => 'TYPO3 offre de nombreuses fonctionnalités pour la création de sites web complexes.', 'weight' => 1],
                'content_modified_at' => time()
            ];
            $page2 = [
                'content' => ['content' => 'La création de sites web complexes est facilitée par les nombreuses fonctionnalités de TYPO3.', 'weight' => 1],
                'content_modified_at' => time() - (60 * 24 * 60 * 60) // 60 jours plus ancien
            ];

            $result = $this->invokeMethod($this->pageAnalysisService, 'calculateSimilarity', [$page1, $page2]);

            $this->assertGreaterThan(0.7, $result['semanticSimilarity'], 'Des contenus similaires avec un wording différent devraient avoir une similarité élevée');
            $this->assertLessThan($result['semanticSimilarity'], $result['finalSimilarity'], 'La similarité finale devrait être inférieure à la similarité sémantique en raison de la différence de date importante');
        }


}