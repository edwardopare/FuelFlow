<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnauthenticatedApiContractTest extends TestCase
{
    #[DataProvider('protectedEndpoints')]
    public function test_protected_api_endpoints_return_json_401_without_accept_header(string $endpoint): void
    {
        $this->get($endpoint)
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            'tenant identity' => ['/api/v1/me'],
            'tenant dashboard' => ['/api/v1/dashboard'],
            'tenant user management' => ['/api/v1/users'],
            'tenant audit events' => ['/api/v1/audit-events'],
            'vendor identity' => ['/api/v1/vendor/me'],
            'vendor dashboard' => ['/api/v1/vendor/dashboard'],
        ];
    }
}
