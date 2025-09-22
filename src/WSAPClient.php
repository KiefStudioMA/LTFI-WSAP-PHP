<?php

namespace LTFI\WSAP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use LTFI\WSAP\Exceptions\AuthenticationException;
use LTFI\WSAP\Exceptions\APIException;
use LTFI\WSAP\Models\Entity;
use LTFI\WSAP\Models\Verification;
use LTFI\WSAP\Models\WSAPData;
use LTFI\WSAP\Models\User;

/**
 * LTFI-WSAP API Client for PHP
 */
class WSAPClient
{
    private string $apiKey;
    private string $baseUrl;
    private Client $httpClient;
    
    /**
     * Create a new WSAP client
     *
     * @param string|null $apiKey API key for authentication (uses LTFI_WSAP_API_KEY env if not provided)
     * @param string $baseUrl Base URL for the API
     * @param int $timeout Request timeout in seconds
     * @throws AuthenticationException
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = 'https://api.ltfi.ai',
        int $timeout = 30
    ) {
        $this->apiKey = $apiKey ?: $_ENV['LTFI_WSAP_API_KEY'] ?? getenv('LTFI_WSAP_API_KEY');
        
        if (empty($this->apiKey)) {
            throw new AuthenticationException('API key required: set LTFI_WSAP_API_KEY or provide in constructor');
        }
        
        $this->baseUrl = $baseUrl;
        
        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'User-Agent' => 'LTFI-WSAP-PHP/2.0.0',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }
    
    /**
     * List entities with optional filtering
     *
     * @param array $params Query parameters for filtering
     * @return array
     * @throws APIException|GuzzleException
     */
    public function listEntities(array $params = []): array
    {
        return $this->request('GET', '/api/entities/', ['query' => $params]);
    }
    
    /**
     * Get a specific entity by ID
     *
     * @param string $id Entity ID or slug
     * @return array
     * @throws APIException|GuzzleException
     */
    public function getEntity(string $id): array
    {
        return $this->request('GET', "/api/entities/{$id}/");
    }
    
    /**
     * Create a new entity
     *
     * @param array $data Entity data
     * @return array
     * @throws APIException|GuzzleException
     */
    public function createEntity(array $data): array
    {
        return $this->request('POST', '/api/entities/', ['json' => $data]);
    }
    
    /**
     * Update an existing entity
     *
     * @param string $id Entity ID or slug
     * @param array $data Update data
     * @return array
     * @throws APIException|GuzzleException
     */
    public function updateEntity(string $id, array $data): array
    {
        return $this->request('PUT', "/api/entities/{$id}/", ['json' => $data]);
    }
    
    /**
     * Delete an entity
     *
     * @param string $id Entity ID or slug
     * @return void
     * @throws APIException|GuzzleException
     */
    public function deleteEntity(string $id): void
    {
        $this->request('DELETE', "/api/entities/{$id}/");
    }
    
    /**
     * Initiate domain verification
     *
     * @param string $domain Domain to verify
     * @param string $method Verification method
     * @return array
     * @throws APIException|GuzzleException
     */
    public function initiateVerification(string $domain, string $method = 'dns_txt'): array
    {
        return $this->request('POST', '/api/verification/initiate/', [
            'json' => [
                'domain' => $domain,
                'method' => $method,
            ],
        ]);
    }
    
    /**
     * Check if a domain is verified
     *
     * @param string $domain Domain to check
     * @return bool
     * @throws APIException|GuzzleException
     */
    public function verifyDomain(string $domain): bool
    {
        try {
            $response = $this->request('POST', '/api/verification/verify/', [
                'json' => ['domain' => $domain],
            ]);
            return $response['verified'] ?? false;
        } catch (APIException $e) {
            return false;
        }
    }
    
    /**
     * Generate WSAP data for an entity
     *
     * @param string $entityId Entity ID
     * @param string $disclosureLevel Disclosure level (BASIC, STANDARD, DETAILED, COMPLETE)
     * @return array
     * @throws APIException|GuzzleException
     */
    public function generateWSAP(string $entityId, string $disclosureLevel = 'STANDARD'): array
    {
        return $this->request('POST', '/api/wsap/generate/', [
            'json' => [
                'entity_id' => $entityId,
                'disclosure_level' => strtoupper($disclosureLevel),
            ],
        ]);
    }
    
    /**
     * Fetch public WSAP data for a domain
     *
     * @param string $domain Domain to fetch WSAP data for
     * @return array
     * @throws APIException|GuzzleException
     */
    public function fetchWSAP(string $domain): array
    {
        return $this->request('GET', "/api/wsap/public/{$domain}/");
    }
    
    /**
     * Get current authenticated user
     *
     * @return array
     * @throws APIException|GuzzleException
     */
    public function getCurrentUser(): array
    {
        return $this->request('GET', '/api/auth/me/');
    }
    
    /**
     * Check API health status
     *
     * @return array
     * @throws APIException|GuzzleException
     */
    public function healthCheck(): array
    {
        return $this->request('GET', '/api/health/');
    }
    
    /**
     * Make an HTTP request to the API
     *
     * @param string $method HTTP method
     * @param string $path API path
     * @param array $options Request options
     * @return array
     * @throws APIException|GuzzleException
     */
    private function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->httpClient->request($method, $path, $options);
            $body = $response->getBody()->getContents();
            
            if (empty($body)) {
                return [];
            }
            
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $statusCode = 0;
            $responseBody = '';
            
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseBody = $e->getResponse()->getBody()->getContents();
            }
            
            throw new APIException(
                sprintf('API error (%d): %s', $statusCode, $responseBody),
                $statusCode
            );
        }
    }
}