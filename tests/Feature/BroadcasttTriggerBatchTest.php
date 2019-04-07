<?php

namespace Tests\Feature;

use Broadcastt\BroadcasttClient;
use Broadcastt\Exception\InvalidChannelNameException;
use Broadcastt\Exception\InvalidHostException;
use Broadcastt\Exception\InvalidSocketIdException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use function GuzzleHttp\Psr7\copy_to_string;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Tests\InvalidDataProviders;

class BroadcasttTriggerBatchTest extends TestCase
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

        $this->client = new BroadcasttClient('testid', 'testkey', 'testsecret');
        $this->client->setLogger($this->logger);
    }

    public function testCanTriggerBatch()
    {
        $expectedBody = file_get_contents(__DIR__ . '/testdata/triggerBatch_request_body.golden');

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

        $batch = [];
        $batch[] = ['channel' => 'test-channel', 'name' => 'test-event', 'data' => ['test-key' => 'test-val']];
        $batch[] = ['channel' => 'test-channel2', 'name' => 'test-event2', 'data' => ['test-key' => 'test-val2']];
        $response = $this->client->triggerBatch($batch);
        $this->assertTrue($response);

        $this->assertCount(1, $container);
        /** @var Request $request */
        $request = $container[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('eu.broadcastt.xyz', $request->getUri()->getHost());
        $this->assertEquals(null, $request->getUri()->getPort());
        $this->assertEquals('/apps/testid/events', $request->getUri()->getPath());
        $this->assertEquals('application/json', $request->getHeader('Content-Type')[0]);

        $this->assertJsonStringEqualsJsonString($expectedBody, copy_to_string($request->getBody()));
        $this->assertRegExp('/^'
            . 'auth_key=testkey'
            . '&auth_signature=\w+'
            . '&auth_timestamp=\d+'
            . '&auth_version=1.0'
            . '&body_md5=' . md5($expectedBody)
            . '$/', $request->getUri()->getQuery());
    }

    /**
     * @param $invalidChannel
     * @dataProvider invalidChannelProvider
     */
    public function testCanNotTriggerBatchWithInvalidChannel($invalidChannel)
    {
        $mockHandler = new MockHandler();

        $handlerStack = HandlerStack::create($mockHandler);

        $guzzleClient = new Client([
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);

        $this->expectException(InvalidChannelNameException::class);

        $batch = [];
        $batch[] = ['channel' => $invalidChannel, 'name' => 'test-event'];
        $this->client->triggerBatch($batch);
    }

    /**
     * @param $invalidSocketId
     * @dataProvider invalidSocketIdProvider
     */
    public function testCanNotTriggerBatchWithInvalidSocketId($invalidSocketId)
    {
        $mockHandler = new MockHandler();

        $handlerStack = HandlerStack::create($mockHandler);

        $guzzleClient = new Client([
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);

        $this->expectException(InvalidSocketIdException::class);

        $batch = [];
        $batch[] = ['channel' => 'test-channel', 'name' => 'test-event', 'socket_id' => $invalidSocketId];
        $this->client->triggerBatch($batch);
    }

    public function testCanNotTriggerBatchWithInvalidHost()
    {
        $mockHandler = new MockHandler();

        $handlerStack = HandlerStack::create($mockHandler);

        $guzzleClient = new Client([
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);
        $this->client->host = 'http://test.xyz';

        $this->expectException(InvalidHostException::class);

        $batch = [];
        $batch[] = ['channel' => 'test-channel', 'name' => 'test-event'];
        $this->client->triggerBatch($batch);
    }

    public function testCanTriggerBatchHandlePayloadTooLargeResponse()
    {
        $mockHandler = new MockHandler([
            new Response(413, [], '{}'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);

        $container = [];
        $history = Middleware::history($container);

        $handlerStack->push($history);

        $guzzleClient = new Client([
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);

        $batch = [];
        $batch[] = ['channel' => 'test-channel', 'name' => 'test-event', 'data' => ['test-key' => 'test-val']];
        $batch[] = ['channel' => 'test-channel2', 'name' => 'test-event2', 'data' => ['test-key' => 'test-val2']];
        $response = $this->client->triggerBatch($batch);
        $this->assertFalse($response);
    }

    public function testCanTriggerBatchHandlePayloadTooLargeResponseWhenGuzzleExceptionsAreDisabled()
    {
        $mockHandler = new MockHandler([
            new Response(413, [], '{}'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);

        $container = [];
        $history = Middleware::history($container);

        $handlerStack->push($history);

        $guzzleClient = new Client([
            'http_errors' => false,
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);

        $batch = [];
        $batch[] = ['channel' => 'test-channel', 'name' => 'test-event', 'data' => ['test-key' => 'test-val']];
        $batch[] = ['channel' => 'test-channel2', 'name' => 'test-event2', 'data' => ['test-key' => 'test-val2']];
        $response = $this->client->triggerBatch($batch);
        $this->assertFalse($response);
    }

}
