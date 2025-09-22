<?php

namespace LTFI\WSAP\Tests;

use LTFI\WSAP\Client;
use LTFI\WSAP\Exceptions\AuthenticationException;
use LTFI\WSAP\Exceptions\NotFoundException;
use LTFI\WSAP\Models\EntityType;
use LTFI\WSAP\Models\DisclosureLevel;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;

class ClientTest extends TestCase
{
    private Client $client;
    private MockHandler $mockHandler;

    protected function setUp(): void
    {
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $httpClient = new HttpClient(['handler' => $handlerStack]);
        
        $this->client = new Client('test-api-key', 'https://api.test.com');
        
        // Use reflection to inject the mock HTTP client
        $reflection = new \ReflectionClass($this->client);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->client, $httpClient);
    }

    public function testClientInitialization(): void
    {
        $testClient = new Client('test-key');
        $this->assertEquals('test-key', $testClient->getApiKey());
        $this->assertEquals('https://api.ltfi.ai', $testClient->getBaseUrl());
    }

    public function testClientWithCustomBaseUrl(): void
    {
        $testClient = new Client('test-key', 'https://custom.api.com');
        $this->assertEquals('https://custom.api.com', $testClient->getBaseUrl());
    }

    public function testListEntities(): void
    {
        $mockResponse = [
            'count' => 1,
            'results' => [[
                'id' => 1,
                'entity_id' => 'test-uuid',
                'entity_type' => 'company',
                'display_name' => 'Test Company',
                'slug' => 'test-company-123',
                'parent_entity' => null,
                'created_by' => 1,
                'is_active' => true,
                'is_published' => true,
                'is_verified' => false,
                'template_id' => null,
                'inherits_from_parent' => false,
                'wsap_data' => (object)[],
                'created_at' => '2023-01-01T00:00:00Z',
                'updated_at' => '2023-01-01T00:00:00Z'
            ]]
        ];

        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockResponse))
        );

        $result = $this->client->listEntities();
        $this->assertEquals(1, $result['count']);
        $this->assertCount(1, $result['results']);
        $this->assertEquals('Test Company', $result['results'][0]['display_name']);
    }

    public function testListEntitiesWithFilters(): void
    {
        $mockResponse = ['count' => 0, 'results' => []];

        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockResponse))
        );

        $result = $this->client->listEntities([
            'entity_type' => 'company',
            'is_verified' => true,
            'page' => 1,
            'page_size' => 10
        ]);

        $this->assertEquals(0, $result['count']);
    }

    public function testGetEntity(): void
    {
        $mockEntity = [
            'id' => 1,
            'entity_id' => 'test-uuid',
            'entity_type' => 'company',
            'display_name' => 'Test Company',
            'slug' => 'test-company-123',
            'parent_entity' => null,
            'created_by' => 1,
            'is_active' => true,
            'is_published' => true,
            'is_verified' => false,
            'template_id' => null,
            'inherits_from_parent' => false,
            'wsap_data' => (object)[],
            'created_at' => '2023-01-01T00:00:00Z',
            'updated_at' => '2023-01-01T00:00:00Z'
        ];

        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockEntity))
        );

        $entity = $this->client->getEntity('test-company-123');
        $this->assertEquals('Test Company', $entity['display_name']);
        $this->assertEquals('test-company-123', $entity['slug']);
    }

    public function testGetEntityNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->mockHandler->append(new Response(404));
        $this->client->getEntity('nonexistent');
    }

    public function testCreateEntity(): void
    {
        $mockEntity = [
            'id' => 1,
            'entity_id' => 'test-uuid',
            'entity_type' => 'company',
            'display_name' => 'New Company',
            'slug' => 'new-company-123',
            'parent_entity' => null,
            'created_by' => 1,
            'is_active' => true,
            'is_published' => false,
            'is_verified' => false,
            'template_id' => null,
            'inherits_from_parent' => false,
            'wsap_data' => (object)[],
            'created_at' => '2023-01-01T00:00:00Z',
            'updated_at' => '2023-01-01T00:00:00Z'
        ];

        $this->mockHandler->append(
            new Response(201, ['Content-Type' => 'application/json'], json_encode($mockEntity))
        );

        $entity = $this->client->createEntity([
            'entity_type' => EntityType::COMPANY,
            'display_name' => 'New Company'
        ]);

        $this->assertEquals('New Company', $entity['display_name']);
        $this->assertEquals('company', $entity['entity_type']);
    }

    public function testInitiateVerification(): void
    {
        $mockVerification = [
            'id' => 'verification-uuid',
            'entity' => 1,
            'domain' => 'example.com',
            'verification_token' => 'test-token',
            'txt_record_name' => '_wsap-verify.example.com',
            'txt_record_value' => 'wsap-verify=test-token',
            'verification_method' => 'dns',
            'status' => 'pending',
            'verified_at' => null,
            'attempts' => 0,
            'max_attempts' => 3
        ];

        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockVerification))
        );

        $result = $this->client->initiateVerification('example.com');
        $this->assertEquals('example.com', $result['domain']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('test-token', $result['verification_token']);
    }

    public function testVerifyDomain(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['verified' => true]))
        );

        $result = $this->client->verifyDomain('example.com');
        $this->assertTrue($result);
    }

    public function testVerifyDomainFalse(): void
    {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['verified' => false]))
        );

        $result = $this->client->verifyDomain('example.com');
        $this->assertFalse($result);
    }

    public function testVerifyDomainError(): void
    {
        $this->mockHandler->append(new Response(500));

        $result = $this->client->verifyDomain('example.com');
        $this->assertFalse($result);
    }

    public function testGenerateWSAP(): void
    {
        $mockWSAP = [
            'version' => '2.0',
            'entity_id' => 'test-company-123',
            'domain' => 'example.com',
            'disclosure_level' => 'STANDARD',
            'generated_at' => '2023-01-01T00:00:00Z',
            'expires_at' => null,
            'data' => (object)[]
        ];

        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockWSAP))
        );

        $result = $this->client->generateWSAP('test-company-123', DisclosureLevel::STANDARD);
        $this->assertEquals('test-company-123', $result['entity_id']);
        $this->assertEquals('2.0', $result['version']);
        $this->assertEquals('STANDARD', $result['disclosure_level']);
    }

    public function testAuthenticationError(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->mockHandler->append(new Response(401));
        $this->client->listEntities();
    }

    public function testNetworkError(): void
    {
        $this->expectException(\Exception::class);

        $this->mockHandler->append(
            new RequestException('Network error', new Request('GET', 'test'))
        );
        $this->client->listEntities();
    }
}