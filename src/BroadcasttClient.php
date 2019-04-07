<?php

namespace Broadcastt;

use Broadcastt\Exception\InvalidArgumentException;
use Broadcastt\Exception\InvalidChannelNameException;
use Broadcastt\Exception\InvalidDataException;
use Broadcastt\Exception\InvalidSocketIdException;
use Broadcastt\Exception\TooManyChannelsException;
use Broadcastt\Exception\InvalidHostException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use function GuzzleHttp\Psr7\stream_for;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;

/**
 * Class BroadcasttClient
 * @package Broadcastt
 *
 * @property-read int $appId Id of your application
 * @property-read string $appKey Key of your application
 * @property-read string $appSecret Secret of your application
 *
 * @property null|ClientInterface $guzzleClient Guzzle Client for sending HTTP requests
 * @property string $scheme e.g. http or https
 * @property string $host The host e.g. cluster.broadcastt.xyz. No trailing forward slash
 * @property int $port The http port
 * @property string $basePath The base of the path what the request will call. `{appId}` can be used to automatically
 *   parse the app ID in the base path.
 * @property int $timeout The http timeout
 */
class BroadcasttClient implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var string Version
     */
    private static $VERSION = '0.2.1';

    /**
     * @var string Auth Version
     */
    private static $AUTH_VERSION = '1.0';

    /**
     * @var string The default second-level domain for clusters
     */
    private static $SLD = '.broadcastt.xyz';

    private $configurations = [
        'appId' => null,
        'appKey' => null,
        'appSecret' => null,
    ];

    /**
     * @var array
     */
    private $modifiers = [
        'guzzleClient' => null,
        'scheme' => null,
        'host' => null,
        'port' => null,
        'basePath' => null,
        'timeout' => null,
    ];


    /**
     * Initializes a new Broadcastt instance with key, secret and ID of an app.
     *
     * @param int $appId Id of your application
     * @param string $appKey Key of your application
     * @param string $appSecret Secret of your application
     * @param string $appCluster Cluster name to connect to.
     */
    public function __construct($appId, $appKey, $appSecret, $appCluster = 'eu')
    {
        $this->configurations['appId'] = $appId;
        $this->configurations['appKey'] = $appKey;
        $this->configurations['appSecret'] = $appSecret;

        $this->scheme = 'http';
        $this->useCluster($appCluster);
        $this->port = 80;
        $this->basePath = '/apps/{appId}';

        $this->timeout = 30;
    }

    /**
     * Clients can be instantiated from a URI. For example: "http://key:secret@eu.broadcastt.com/apps/{appId}"
     *
     * @param string|UriInterface $uri
     * @return BroadcasttClient
     */
    public static function fromUri($uri)
    {
        if (!($uri instanceof UriInterface)) {
            $uri = new Uri($uri);
        }

        preg_match('#^/apps/(\d+)$#', $uri->getPath(), $matches);
        if (count($matches) !== 2) {
            throw new InvalidArgumentException('App ID not found in URI');
        }

        $appId = $matches[1];

        if (!$uri->getUserInfo()) {
            throw new InvalidArgumentException('User info is missing from URI');
        }

        $userInfo = explode(':', $uri->getUserInfo(), 2);

        if (count($userInfo) < 2) {
            throw new InvalidArgumentException('Secret part of user info is missing from URI');
        }

        list($appKey, $appSecret) = $userInfo;

        $client = new BroadcasttClient($appId, $appKey, $appSecret);
        $client->scheme = $uri->getScheme();
        $client->host = $uri->getHost();
        $client->port = $uri->getPort();

        return $client;
    }

    /**
     * Log a string.
     *
     * @param string $msg The message to log
     * @param array $context [optional] Any extraneous information that does not fit well in a string.
     * @param string $level [optional] Importance of log message, highly recommended to use Psr\Log\LogLevel::{level}
     *
     * @return void
     */
    private function log($msg, array $context = [], $level = LogLevel::INFO)
    {
        if (is_null($this->logger)) {
            return;
        }

        $this->logger->log($level, $msg, $context);
    }

    /**
     * Validate number of channels and channel name format.
     *
     * @param string[] $channels An array of channel names to validate
     *
     * @return void
     */
    private function validateChannels($channels)
    {
        if (count($channels) > 100) {
            throw new TooManyChannelsException('An event can be triggered on a maximum of 100 channels in a single call.');
        }

        foreach ($channels as $channel) {
            $this->validateChannel($channel);
        }
    }

    /**
     * Ensure a channel name is valid based on our specification.
     *
     * @param string $channel The channel name to validate
     *
     * @return void
     */
    private function validateChannel($channel)
    {
        if ($channel === null || !preg_match('/\A[-a-zA-Z0-9_=@,.;]+\z/', $channel)) {
            throw new InvalidChannelNameException('Invalid channel name ' . $channel);
        }
    }

    /**
     * Ensure a socket_id is valid based on our specification.
     *
     * @param string $socketId The socket ID to validate
     */
    private function validateSocketId($socketId)
    {
        if ($socketId !== null && !preg_match('/\A\d+\.\d+\z/', $socketId)) {
            throw new InvalidSocketIdException('Invalid socket ID ' . $socketId);
        }
    }

    /**
     * Utility function used to build a request instance.
     *
     * @param string $domain
     * @param string $path
     * @param string $requestMethod
     * @param array $queryParams
     *
     * @return Request
     */
    private function buildRequest($domain, $path, $requestMethod = 'GET', $queryParams = [])
    {
        $path = strtr($path, ['{appId}' => $this->appId]);

        // Create the signed signature...
        $signedQuery = $this->buildAuthQueryString($requestMethod, $path, $queryParams);

        $uri = $domain . $path . '?' . $signedQuery;

        $this->log('buildRequest uri: {uri}', ['uri' => $uri]);

        $headers = [
            'Content-Type' => 'application/json',
            'Expect' => '',
            'X-Library' => 'broadcastt-php ' . self::$VERSION,
        ];

        return new Request($requestMethod, $uri, $headers);
    }

    /**
     * Utility function to send a request and capture response information.
     *
     * @param RequestInterface $request
     *
     * @return Response
     * @throws GuzzleException
     */
    private function sendRequest($request)
    {
        if ($this->guzzleClient === null) {
            $this->guzzleClient = new Client();
        }

        $this->log('sendRequest request: {request}', ['request' => $request]);

        try {
            $response = $this->guzzleClient->send($request);
        } catch (GuzzleException $exception) {
            $this->log('sendRequest error: {exception}', ['exception' => $exception], LogLevel::ERROR);

            throw $exception;
        }

        $this->log('sendRequest response: {response}', ['response' => $response]);

        return $response;
    }

    /**
     * Build the URI.
     *
     * @return string
     */
    private function buildUri()
    {
        if (preg_match('/^http[s]?\:\/\//', $this->host) !== 0) {
            throw new InvalidHostException("Invalid host value. Host must not start with http or https.");
        }

        return $this->scheme . '://' . $this->host . ':' . $this->port;
    }

    /**
     * Check if the status code indicates the request was successful.
     *
     * @param $status
     * @return bool
     */
    private function isSuccessStatusCode($status)
    {
        return 2 === (int)floor($status / 100);
    }

    /**
     * Build the required HMAC'd auth string.
     *
     * @param string $requestMethod
     * @param string $requestPath
     * @param array $queryParams [optional]
     * @param null $time
     *
     * @return string
     */
    public function buildAuthQueryString($requestMethod, $requestPath, $queryParams = [], $time = null)
    {
        $params = [];
        $params['auth_key'] = $this->appKey;
        $params['auth_timestamp'] = $time ?? time();
        $params['auth_version'] = self::$AUTH_VERSION;

        $params = array_merge($params, $queryParams);
        ksort($params);

        $stringToSign = "$requestMethod\n" . $requestPath . "\n" . self::httpBuildQuery($params);

        $authSignature = hash_hmac('sha256', $stringToSign, $this->appSecret, false);

        $params['auth_signature'] = $authSignature;
        ksort($params);

        $authQueryString = self::httpBuildQuery($params);

        return $authQueryString;
    }

    /**
     * Generate URL-encoded query string in which nested elements are represented as comma-separated values.
     *
     * @param array|string $array The array to implode
     *
     * @return string The imploded array
     */
    public static function httpBuildQuery($array)
    {
        $string = [];
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $val = implode(',', $val);
            }
            $string[] = "{$key}={$val}";
        }

        return implode('&', $string);
    }

    /**
     * Trigger an event by providing event name and payload.
     * Optionally provide a socket ID to exclude a client (most likely the sender).
     *
     * @param array|string $channels A channel name or an array of channel names to publish the event on.
     * @param string $name Name of the event
     * @param mixed $data Event data
     * @param string|null $socketId [optional]
     * @param bool $jsonEncoded [optional]
     *
     * @return bool
     * invalid
     */
    public function trigger($channels, $name, $data, $socketId = null, $jsonEncoded = false)
    {
        if (is_string($channels) === true) {
            $channels = [$channels];
        }

        $this->validateChannels($channels);
        $this->validateSocketId($socketId);

        if (!$jsonEncoded) {
            $data = json_encode($data);

            // json_encode might return false on failure
            if (!$data) {
                $this->log('Failed to perform json_encode on the the provided data: {error}', [
                    'error' => $data,
                ], LogLevel::ERROR);
            }
        }

        $postParams = [];
        $postParams['name'] = $name;
        $postParams['data'] = $data;
        $postParams['channels'] = $channels;

        if ($socketId !== null) {
            $postParams['socket_id'] = $socketId;
        }

        try {
            $response = $this->post('/event', [], $postParams);

            return $this->isSuccessStatusCode($response->getStatusCode());
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * Trigger multiple events at the same time.
     *
     * @param array $batch [optional] An array of events to send
     * @param bool $jsonEncoded [optional] Defines if the data is already encoded
     *
     * @return bool
     */
    public function triggerBatch($batch = [], $jsonEncoded = false)
    {
        foreach ($batch as $key => $event) {
            $this->validateChannel($event['channel'] ?? null);
            $this->validateSocketId($event['socket_id'] ?? null);

            if (!array_key_exists('data', $event)) {
                throw new InvalidDataException('Data is missing from event');
            }

            if (!$jsonEncoded) {
                $batch[$key]['data'] = json_encode($event['data']);
            }
        }

        $postParams = [];
        $postParams['batch'] = $batch;


        try {
            $response = $this->post('/events', [], $postParams);

            return $this->isSuccessStatusCode($response->getStatusCode());
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * POST arbitrary REST API resource using a synchronous http client.
     * All request signing is handled automatically.
     *
     * @param string $path Path excluding /apps/{appId}
     * @param array $queryParams API query params (see https://broadcastt.xyz/docs/References-‐-Rest-API)
     * @param array $postParams API post params (see https://broadcastt.xyz/docs/References-‐-Rest-API)
     *
     * @return Response
     * @throws GuzzleException
     */
    private function post($path, $queryParams = [], $postParams = [])
    {
        $path = $this->basePath . $path;

        $postValue = json_encode($postParams);

        $queryParams['body_md5'] = md5($postValue);

        $request = $this->buildRequest($this->buildUri(), $path, 'POST', $queryParams)
            ->withBody(stream_for($postValue));

        return $this->sendRequest($request);
    }

    /**
     * GET arbitrary REST API resource using a synchronous http client.
     * All request signing is handled automatically.
     *
     * @param string $path Path excluding /apps/{appId}
     * @param array $queryParams API query params (see https://broadcastt.xyz/docs/References-‐-Rest-API)
     *
     * @return Response See Broadcastt API docs
     * @throws GuzzleException
     */
    public function get($path, $queryParams = [])
    {
        $path = $this->basePath . $path;

        if (is_string($queryParams)) {
            parse_str($queryParams, $result);
            $queryParams = $result;
        }

        $ch = $this->buildRequest($this->buildUri(), $path, 'GET', $queryParams);

        return $this->sendRequest($ch);
    }

    /**
     * Creates a socket signature.
     *
     * @param string $channel
     * @param string $socketId
     * @param string $customData
     *
     * @return string Json encoded authentication string.
     */
    public function privateAuth($channel, $socketId, $customData = null)
    {
        $this->validateChannel($channel);
        $this->validateSocketId($socketId);

        if ($customData) {
            $signature = hash_hmac('sha256', $socketId . ':' . $channel . ':' . $customData, $this->appSecret, false);
        } else {
            $signature = hash_hmac('sha256', $socketId . ':' . $channel, $this->appSecret, false);
        }

        $signature = ['auth' => $this->appKey . ':' . $signature];
        // add the custom data if it has been supplied
        if ($customData) {
            $signature['channel_data'] = $customData;
        }

        return json_encode($signature);
    }

    /**
     * Creates a presence signature (an extension of socket signing).
     *
     * @param string $channel
     * @param string $socketId
     * @param string $userId
     * @param mixed $userInfo
     *
     * @return string
     */
    public function presenceAuth($channel, $socketId, $userId, $userInfo = null)
    {
        $userData = ['user_id' => $userId];
        if ($userInfo) {
            $userData['user_info'] = $userInfo;
        }

        return $this->privateAuth($channel, $socketId, json_encode($userData));
    }

    /**
     * Modifies the `host` value for given cluster
     *
     * @param $cluster
     */
    public function useCluster($cluster)
    {
        $this->host = $cluster . self::$SLD;
    }

    /**
     * Short way to change `scheme` to `https` and `port` to `443`
     */
    public function useTLS()
    {
        $this->scheme = 'https';

        if ($this->port === 80) {
            $this->port = 443;
        }
    }

    /**
     * @return ClientInterface|null
     */
    public function getGuzzleClient(): ?ClientInterface
    {
        return $this->guzzleClient;
    }

    /**
     * @param ClientInterface|null $guzzleClient
     */
    public function setGuzzleClient(?ClientInterface $guzzleClient): void
    {
        $this->guzzleClient = $guzzleClient;
    }

    public function __get($name)
    {
        return $this->configurations[$name] ?? $this->modifiers[$name] ?? null;
    }

    public function __set($name, $value)
    {
        if (array_key_exists($name, $this->modifiers)) {
            $this->modifiers[$name] = $value;
        }
    }

}
