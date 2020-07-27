<?php

namespace Tests\Feature;

use Broadcastt\BroadcasttClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Tests\InvalidDataProviders;

class BroadcasttGetTest extends TestCase
{
    use InvalidDataProviders;

    /**
     * @var TestLogger
     */
    private $logger;

    /**
     * @var BroadcasttClient
     */
    private $client;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();

        $this->client = new BroadcasttClient(1, 'testkey', 'testsecret');
        $this->client->setLogger($this->logger);
    }

    public function queryParamsProvider(): array
    {
        return [
            'Array' => [['test-param' => 'test-val']],
            'String' => ['test-param=test-val'],
        ];
    }

    /**
     * @param $queryParams
     * @throws GuzzleException
     * @dataProvider queryParamsProvider
     */
    public function testCanGet($queryParams): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], '{}'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);

        $container = [];
        $history = Middleware::history($container);

        $handlerStack->push($history);

        $guzzleClient = new Client([
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);

        $response = $this->client->get('/test/path', $queryParams);
        $this->assertNotNull($response);
        $this->assertInstanceOf(Response::class, $response);

        $this->assertCount(1, $container);
        /** @var Request $request */
        $request = $container[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('eu.broadcastt.xyz', $request->getUri()->getHost());
        $this->assertEquals(null, $request->getUri()->getPort());
        $this->assertEquals('/apps/1/test/path', $request->getUri()->getPath());
        $this->assertEquals('application/json', $request->getHeader('Content-Type')[0]);

        $this->assertRegExp('/^'
            . 'auth_key=testkey'
            . '&auth_signature=\w+'
            . '&auth_timestamp=\d+'
            . '&auth_version=1.0'
            . '&test-param=test-val'
            . '$/', $request->getUri()->getQuery());
    }
}