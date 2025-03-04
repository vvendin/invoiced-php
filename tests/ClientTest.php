<?php

namespace Invoiced\Tests;

use Firebase\JWT\JWT;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Invoiced\Client;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ClientTest extends TestCase
{
    /**
     * @return void
     */
    public function testNoApiKey()
    {
        $this->expectException('InvalidArgumentException');

        $client = new Client('');
    }

    /**
     * @return void
     */
    public function testApiKey()
    {
        $client = new Client('test');
        $this->assertEquals('test', $client->apiKey());
        $this->assertEquals('https://api.invoiced.com', $client->endpoint());
    }

    /**
     * @return void
     */
    public function testSandbox()
    {
        $client = new Client('test', true);
        $this->assertEquals('https://api.sandbox.invoiced.com', $client->endpoint());
    }

    /**
     * @return void
     */
    public function testRequest()
    {
        $mock = new MockHandler([
            new Response(200, ['X-Foo' => 'Bar'], '{"test":true}'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $response = $client->request('GET', '/invoices', ['per_page' => 3]);

        $expected = [
            'code'    => 200,
            'headers' => [
                'X-Foo' => 'Bar',
            ],
            'body' => [
                'test' => 1,
            ],
        ];
        $this->assertEquals($expected, $response);
    }

    /**
     * @return void
     */
    public function testRequestPost()
    {
        $mock = new MockHandler([
            new Response(201, ['X-Foo' => 'Bar'], '{"test":true}'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $response = $client->request('POST', '/invoices', ['customer' => 123]);

        $expected = [
            'code'    => 201,
            'headers' => [
                'X-Foo' => 'Bar',
            ],
            'body' => [
                'test' => 1,
            ],
        ];
        $this->assertEquals($expected, $response);
    }

    /**
     * @return void
     */
    public function testRequestIdempotentPost()
    {
        $mock = new MockHandler([
            new Response(201, ['X-Foo' => 'Bar'], '{"test":true}'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $response = $client->request('POST', '/invoices', ['customer' => 123], ['idempotency_key' => 'a random value']);

        $expected = [
            'code'    => 201,
            'headers' => [
                'X-Foo' => 'Bar',
            ],
            'body' => [
                'test' => 1,
            ],
        ];
        $this->assertEquals($expected, $response);
    }

    /**
     * @return void
     */
    public function testRequestInvalidJson()
    {
        $this->expectException('Invoiced\\Error\\ApiError');

        $mock = new MockHandler([
            new Response(200, [], 'not valid json'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $client->request('GET', '/invoices');
    }

    /**
     * @return void
     */
    public function testRequestAuthError()
    {
        $this->expectException('Invoiced\\Error\\AuthenticationError');

        $mock = new MockHandler([
            new Response(401, [], '{"error":"invalid_request","message":"invalid api key"}'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $client->request('GET', '/invoices');
    }

    /**
     * @return void
     */
    public function testRequestInvalid()
    {
        $this->expectException('Invoiced\\Error\\InvalidRequest');

        $mock = new MockHandler([
            new Response(400, [], '{"error":"rate_limit","message":"not found"}'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $client->request('GET', '/invoices');
    }

    /**
     * @return void
     */
    public function testRequestRateLimitError()
    {
        $this->expectException('Invoiced\\Error\\RateLimitError');

        $mock = new MockHandler([
            new Response(429, [], '{"error":"rate_limit_error","message":"rate limit reached"}'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $client->request('GET', '/invoices');
    }

    /**
     * @return void
     */
    public function testRequestApiError()
    {
        $this->expectException('Invoiced\\Error\\ApiError');

        $mock = new MockHandler([
            new Response(500, [], '{"error":"api","message":"idk"}'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $client->request('GET', '/invoices');
    }

    /**
     * @return void
     */
    public function testRequestGeneralApiError()
    {
        $this->expectException('Invoiced\\Error\\ApiError');

        $mock = new MockHandler([
            new Response(502, [], '{"error":"api","message":"idk"}'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $client->request('GET', '/invoices');
    }

    /**
     * @return void
     */
    public function testRequestApiErrorInvalidJson()
    {
        $this->expectException('Invoiced\\Error\\ApiError');

        $mock = new MockHandler([
            new Response(500, [], 'not valid json'),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $client->request('GET', '/invoices');
    }

    /**
     * @return void
     */
    public function testRequestConnectionError()
    {
        $this->expectException('Invoiced\\Error\\ApiConnectionError');

        $request = new Request('GET', 'https://api.invoiced.com');
        $mock = new MockHandler([
            new RequestException('Could not connect', $request),
        ]);

        $client = new Client('API_KEY', false, null, $mock);

        $client->request('GET', '/invoices');
    }

    /**
     * @return void
     */
    public function testGenerateLoginToken()
    {
        $ssoKey = '8baa4dbc338a54bbf7696eef3ee4aa2daadd61bba85fcfe8df96c7cfa227c43';
        $client = new Client('API_KEY', false, $ssoKey);

        $t = time();
        $token = $client->generateSignInToken(1234, 3600);

        $decrypted = (array) JWT::decode($token, $ssoKey, ['HS256']);

        $this->assertLessThan(3, $decrypted['exp'] - $t - 3600); // this accounts for slow running tests
        unset($decrypted['exp']);

        $expected = [
            'iat' => $t,
            'sub' => 1234,
            'iss' => 'Invoiced PHP/'.Client::VERSION,
        ];
        $this->assertEquals($expected, $decrypted);
    }

    /**
     * @return void
     */
    public function testGenerateSignInTokenNoSSOKey()
    {
        $this->expectException('InvalidArgumentException');
        $client = new Client('API_KEY');

        $client->generateSignInToken(1234, 3600);
    }
}
