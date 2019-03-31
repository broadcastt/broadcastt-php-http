<?php

namespace Tests\Unit;

use Broadcastt\BroadcasttClient;
use Broadcastt\BroadcasttException;
use PHPUnit\Framework\TestCase;
use Tests\InvalidDataProviders;

class BroadcasttClientTest extends TestCase
{
    use InvalidDataProviders;

    /**
     * @var BroadcasttClient
     */
    private $client;

    protected function setUp(): void
    {
        $this->client = new BroadcasttClient('testid', 'testkey', 'testsecret');
    }

    public function testDefaultValuesAreCorrect()
    {
        $this->assertEquals('http', $this->client->getScheme());
        $this->assertEquals('eu.broadcastt.xyz', $this->client->getHost());
        $this->assertEquals(80, $this->client->getPort());
        $this->assertEquals('/apps/{appId}', $this->client->getBasePath());

        $this->assertEquals(30, $this->client->getTimeout());
    }

    public function testCanUseTLSChangeSchemeAndDefaultPort()
    {
        $this->client->useTLS();

        $this->assertEquals('https', $this->client->getScheme());
        $this->assertEquals(443, $this->client->getPort());
    }

    public function testCanUseTLSMethodKeepNonDefaultPort()
    {
        $this->client->setPort(8000);
        $this->client->useTLS();

        $this->assertEquals('https', $this->client->getScheme());
        $this->assertEquals(8000, $this->client->getPort());
    }

    public function testCanClusterMethodChangeHost()
    {
        $this->client->useCluster('us');

        $this->assertEquals('us.broadcastt.xyz', $this->client->getHost());
    }

    public function httpBuildQueryProvider()
    {
        return [
            'One Simple Value' => [
                'testKey=testValue',
                ['testKey' => 'testValue'],
            ],
            'Two Simple Value' => [
                'testKey=testValue&testKey2=testValue2',
                ['testKey' => 'testValue', 'testKey2' => 'testValue2'],
            ],
            'One Array Value' => [
                'testKey=testValue,testValue2',
                ['testKey' => ['testValue', 'testValue2']],
            ],
        ];
    }

    /**
     * @param $expected
     * @param $data
     *
     * @dataProvider httpBuildQueryProvider
     */
    public function testCanHttpBuildQueryMethodBuildCorrectString($expected, $data)
    {
        $actual = BroadcasttClient::httpBuildQuery($data);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @param $invalidChannel
     *
     * @throws BroadcasttException
     * @dataProvider invalidChannelProvider
     */
    public function testCanPrivateAuthMethodThrowExceptionForInvalidChannel($invalidChannel)
    {
        $this->expectException(BroadcasttException::class);

        $this->client->privateAuth($invalidChannel, '1.1');
    }

    /**
     * @param $invalidSocketId
     *
     * @throws BroadcasttException
     * @dataProvider invalidSocketIdProvider
     */
    public function testCanPrivateAuthMethodThrowExceptionForInvalidSocketId($invalidSocketId)
    {
        $this->expectException(BroadcasttException::class);

        $this->client->privateAuth('test-channel', $invalidSocketId);
    }

    public function validPrivateAuthDetailsProvider()
    {
        return [
            'Simple' => [
                '{"auth":"testkey:67b492396edbe136bed8a131fd3c5ba7c28316a0a93c083973ecf69ceb2b474b"}',
                'test-channel',
                '1.1',
            ],
            'Complex' => [
                '{"auth":"testkey:de12ed26697ecc190d34faaaf4af9090aac64eef5ace17096a757407a167cddf"}',
                '-azAZ9_=@,.;',
                '98765.12345678',
            ],
        ];
    }

    /**
     * @param $expected
     * @param $channel
     * @param $socketId
     *
     * @throws BroadcasttException
     * @dataProvider validPrivateAuthDetailsProvider
     */
    public function testCanPrivateAuthMethodBuildCorrectString($expected, $channel, $socketId)
    {
        $actual = $this->client->privateAuth($channel, $socketId);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @param $invalidChannel
     *
     * @throws BroadcasttException
     * @dataProvider invalidChannelProvider
     */
    public function testCanPresenceAuthMethodThrowExceptionForInvalidChannel($invalidChannel)
    {
        $this->expectException(BroadcasttException::class);

        $this->client->presenceAuth($invalidChannel, '1.1', 'id');
    }

    /**
     * @param $invalidSocketId
     *
     * @throws BroadcasttException
     * @dataProvider invalidSocketIdProvider
     */
    public function testCanPresenceAuthMethodThrowExceptionForInvalidSocketId($invalidSocketId)
    {
        $this->expectException(BroadcasttException::class);

        $this->client->presenceAuth('test-channel', $invalidSocketId, 'id');
    }

    public function validPresenceAuthDetailsProvider()
    {
        return [
            'Only Id' => [
                '"testkey:0fe707aa1078ae440c69cb38922998d561b90c321e9b96db48cde808022679c7"',
                '"{\"user_id\":\"id\"}"',
                'id',
                null,
            ],
            'With User Info' => [
                '"testkey:7e8714a77b50ead51790766f17c3e6a7226e31f0b2b073df676820a7f1ada932"',
                '"{\"user_id\":\"id\",\"user_info\":{\"info-param\":\"info-value\"}}"',
                'id',
                ['info-param' => 'info-value'],
            ],
        ];
    }

    /**
     * @param $expectedAuth
     * @param $expectedData
     * @param $userId
     * @param $userInfo
     *
     * @throws BroadcasttException
     * @dataProvider validPresenceAuthDetailsProvider
     */
    public function testCanPresenceAuthMethodBuildCorrectString($expectedAuth, $expectedData, $userId, $userInfo)
    {
        $actual = $this->client->presenceAuth('test-channel', '1.1', $userId, $userInfo);

        $this->assertEquals('{"auth":' . $expectedAuth . ',"channel_data":' . $expectedData . '}', $actual);
    }

    public function testCanBuildCorrectSignature()
    {
        $requestMethod = 'POST';
        $requestPath = '/test/path';
        $queryParams = [
            'test_param_name' => 'test_param_value',
        ];
        $time = 1553345934;

        $actualAuthQueryString = $this->client->buildAuthQueryString(
            $requestMethod,
            $requestPath,
            $queryParams,
            $time
        );

        $expectedStringToSign = "POST\n"
            . "/test/path\n"
            . "auth_key=testkey&auth_timestamp=1553345934&auth_version=1.0&test_param_name=test_param_value";
        $expectedSignature = hash_hmac('sha256', $expectedStringToSign, 'testsecret', false);
        $expectedAuthQueryString = 'auth_key=testkey'
            . '&auth_signature=' . $expectedSignature
            . '&auth_timestamp=1553345934'
            . '&auth_version=1.0'
            . '&test_param_name=test_param_value';

        $this->assertEquals($expectedAuthQueryString, $actualAuthQueryString);
    }
}
