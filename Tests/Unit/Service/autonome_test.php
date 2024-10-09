<?php

class FaissApiTester
{
    private $apiUrl;

    public function __construct(string $apiUrl)
    {
        $this->apiUrl = $apiUrl;
    }

    public function runTests()
    {
        echo "Running FAISS API tests...\n\n";

        $this->testClearIndex();
        $this->testAddTexts();
        $this->testFindSimilar();
        $this->testStatus();
        $this->testRateLimit();
        $this->testInvalidRequest();
    }

    private function testClearIndex()
    {
        echo "Testing Clear Index...\n";
        $response = $this->sendRequest('POST', '/clear_faiss_index');
        $this->assertResponse($response, 200, 'Index cleared successfully');
    }

    private function testAddTexts()
    {
        echo "Testing Add Texts...\n";
        $texts = [
            ['id' => '1', 'text' => 'TYPO3 is a free and open-source web content management system written in PHP.'],
            ['id' => '2', 'text' => 'PHP is a popular general-purpose scripting language that is especially suited to web development.'],
            ['id' => '3', 'text' => 'Web content management systems are used to manage and simplify the publication of web content.']
        ];
        $response = $this->sendRequest('POST', '/add_texts', ['items' => $texts]);
        $this->assertResponse($response, 200, 'Added/Updated 3 texts');
    }

    private function testFindSimilar()
    {
        echo "Testing Find Similar...\n";
        $query = ['id' => '1', 'text' => 'TYPO3 content management', 'k' => 2];
        $response = $this->sendRequest('POST', '/find_similar', $query);
        $this->assertResponse($response, 200);
        $result = json_decode($response['body'], true);
        $this->assertTrue(is_array($result) && count($result) == 2, "Expected 2 similar results");
        $this->assertTrue($result[0]['id'] == '1' && $result[0]['similarity'] > 0.8, "Expected first result to be highly similar");
    }

    private function testStatus()
    {
        echo "Testing Status...\n";
        $response = $this->sendRequest('GET', '/faiss_similarity_status');
        $this->assertResponse($response, 200);
        $result = json_decode($response['body'], true);
        $this->assertTrue(isset($result['num_texts']) && $result['num_texts'] == 3, "Expected 3 texts in index");
    }

    private function testRateLimit()
    {
        echo "Testing Rate Limit...\n";
        for ($i = 0; $i < 10; $i++) {
            $response = $this->sendRequest('GET', '/faiss_similarity_status');
            if ($response['status'] == 429) {
                echo "Rate limit hit as expected.\n";
                return;
            }
        }
        echo "WARNING: Rate limit not reached as expected.\n";
    }

    private function testInvalidRequest()
    {
        echo "Testing Invalid Request...\n";
        $response = $this->sendRequest('POST', '/add_texts', ['invalid' => 'data']);
        $this->assertResponse($response, 400, 'Invalid request');
    }

    private function sendRequest($method, $endpoint, $data = null)
    {
        $url = $this->apiUrl . $endpoint;
        $options = [
            'http' => [
                'method' => $method,
                'header' => 'Content-Type: application/json',
                'ignore_errors' => true
            ]
        ];

        if ($data !== null) {
            $options['http']['content'] = json_encode($data);
        }

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        return [
            'status' => $http_response_header[0],
            'body' => $result
        ];
    }

    private function assertResponse($response, $expectedStatus, $expectedMessage = null)
    {
        $actualStatus = substr($response['status'], 9, 3);
        $body = json_decode($response['body'], true);

        if ($actualStatus != $expectedStatus) {
            echo "ERROR: Expected status $expectedStatus, got $actualStatus\n";
            return;
        }

        if ($expectedMessage !== null && (!isset($body['message']) || $body['message'] !== $expectedMessage)) {
            echo "ERROR: Expected message '$expectedMessage', got '" . ($body['message'] ?? 'no message') . "'\n";
            return;
        }

        echo "SUCCESS: " . ($expectedMessage ?? "Status $expectedStatus") . "\n";
    }

    private function assertTrue($condition, $message)
    {
        if (!$condition) {
            echo "ERROR: $message\n";
        } else {
            echo "SUCCESS: $message\n";
        }
    }
}

// Usage
$apiUrl = 'https://nlpservice.semantic-suggestion.com/api';  // Replace with your actual API URL
$tester = new FaissApiTester($apiUrl);
$tester->runTests();