<?php

namespace Tests\Feature;

use Broadcastt\BroadcasttClient;
use Broadcastt\Exception\InvalidSocketIdException;
use Broadcastt\Exception\JsonEncodeException;
use Broadcastt\Exception\TooManyChannelsException;
use Broadcastt\Exception\InvalidHostException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use function GuzzleHttp\Psr7\copy_to_string;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Tests\InvalidDataProviders;

class BroadcasttTriggerTest extends TestCase
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

    public function channelDataProvider()
    {
        return [
            'String' => [
                'trigger_string_request_body.golden',
                'test-data'
            ],
            'Hash' => [
                'trigger_hash_request_body.golden',
                ['test-key' => 'test-val'],
            ],
        ];
    }

    /**
     * @param $goldenBody
     * @param $channelData
     * @dataProvider channelDataProvider
     */
    public function testCanTriggerData($goldenBody, $channelData)
    {
        $expectedBody = file_get_contents(__DIR__ . '/testdata/' . $goldenBody);

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

        $response = $this->client->trigger('test-channel', 'test-event', $channelData);
        $this->assertTrue($response);

        $this->assertCount(1, $container);
        /** @var Request $request */
        $request = $container[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('eu.broadcastt.xyz', $request->getUri()->getHost());
        $this->assertEquals(null, $request->getUri()->getPort());
        $this->assertEquals('/apps/1/event', $request->getUri()->getPath());
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

    public function testCanTriggerWithSocketId()
    {
        $expectedBody = file_get_contents(__DIR__ . '/testdata/trigger_with_socket_id_request_body.golden');

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

        $response = $this->client->trigger('test-channel', 'test-event', 'test-data', '1.1');
        $this->assertTrue($response);

        $this->assertCount(1, $container);
        /** @var Request $request */
        $request = $container[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('eu.broadcastt.xyz', $request->getUri()->getHost());
        $this->assertEquals(null, $request->getUri()->getPort());
        $this->assertEquals('/apps/1/event', $request->getUri()->getPath());
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
     * @dataProvider invalidChannelsProvider
     */
    public function testCanNotTriggerWithInvalidChannel($invalidChannel)
    {
        $mockHandler = new MockHandler();

        $handlerStack = HandlerStack::create($mockHandler);

        $guzzleClient = new Client([
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);

        $this->expectException(InvalidArgumentException::class);
        $this->client->trigger($invalidChannel, 'test-event', '');
    }

    public function testCanNotTriggerWithMoreThanHundredChannel()
    {
        $mockHandler = new MockHandler();

        $handlerStack = HandlerStack::create($mockHandler);

        $guzzleClient = new Client([
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);

        $channels = [];
        for ($i = 0; $i < 101; $i++) {
            $channels[] = 'test-channel' . $i;
        }
        $this->expectException(TooManyChannelsException::class);
        $this->client->trigger($channels, 'test-event', '');
    }

    /**
     * @param $invalidSocketId
     * @dataProvider invalidSocketIdProvider
     */
    public function testCanNotTriggerWithInvalidSocketId($invalidSocketId)
    {
        $mockHandler = new MockHandler();

        $handlerStack = HandlerStack::create($mockHandler);

        $guzzleClient = new Client([
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);

        $this->expectException(InvalidSocketIdException::class);
        $this->client->trigger('test-channel', 'test-event', '', $invalidSocketId);
    }

    public function testCanNotTriggerWithInvalidHost()
    {
        $mockHandler = new MockHandler();

        $handlerStack = HandlerStack::create($mockHandler);

        $guzzleClient = new Client([
            'handler' => $handlerStack,
        ]);

        $this->client->setGuzzleClient($guzzleClient);
        $this->client->host = 'http://test.xyz';

        $this->expectException(InvalidHostException::class);
        $this->client->trigger('test-channel', 'test-event', '');
    }

    public function testCanTriggerThrowExceptionOnPayloadTooLargeResponse()
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

        $this->expectException(GuzzleException::class);
        $this->client->trigger('test-channel', 'test-event', '');
    }

    public function testCanTriggerHandlePayloadTooLargeResponseWhenGuzzleExceptionsAreDisabled()
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

        $response = $this->client->trigger('test-channel', 'test-event', '');
        $this->assertFalse($response);
    }

    public function testCanTriggerThrowExceptionOnJsonEncodeFailure()
    {
        // data from https://www.php.net/manual/en/function.json-last-error.php
        $data = "\xB1\x31";

        $this->expectException(JsonEncodeException::class);
        try {
            $this->client->trigger('test-channel', 'test-event', $data);
        } catch (JsonEncodeException $e) {
            $this->assertEquals($e->getData(), $data);
            throw $e;
        }
    }
}
