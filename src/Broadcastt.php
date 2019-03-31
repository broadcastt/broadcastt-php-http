<?php

namespace Broadcastt;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;

class Broadcastt implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var string Version
     */
    private static $VERSION = '0.1.0';

    /**
     * @var string Auth Version
     */
    private static $AUTH_VERSION = '1.0';

    /**
     * @var string The default second-level domain for clusters
     */
    private static $SLD = '.broadcastt.xyz';

    /**
     * @var null|resource
     */
    private $curlHandler = null;

    /**
     * @var string
     */
    private $appId;

    /**
     * @var string
     */
    private $appKey;

    /**
     * @var string
     */
    private $appSecret;

    /**
     * @var string e.g. http or https
     */
    private $scheme;

    /**
     * @var string The host e.g. cluster.broadcastt.xyz. No trailing forward slash
     */
    private $host;

    /**
     * @var int The http port
     */
    private $port;

    /**
     * @var string
     */
    private $basePath;

    /**
     * @var int The http timeout
     */
    private $timeout;

    /**
     * @var array
     */
    private $curlOptions;

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
        $this->appId = $appId;
        $this->appKey = $appKey;
        $this->appSecret = $appSecret;

        $this->scheme = 'http';
        $this->useCluster($appCluster);
        $this->port = 80;
        $this->basePath = '/apps/{appId}';

        $this->curlOptions = [];

        $this->timeout = 30;
    }

    /**
     * Log a string.
     *
     * @param string $msg The message to log
     * @param array|\Exception $context [optional] Any extraneous information that does not fit well in a string.
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
     * @throws BroadcasttException If $channels is too big or any channel is invalid
     *
     * @return void
     */
    private function validateChannels($channels)
    {
        if (count($channels) > 100) {
            throw new BroadcasttException('An event can be triggered on a maximum of 100 channels in a single call.');
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
     * @throws BroadcasttException If $channel is invalid
     *
     * @return void
     */
    private function validateChannel($channel)
    {
        if (! preg_match('/\A[-a-zA-Z0-9_=@,.;]+\z/', $channel)) {
            throw new BroadcasttException('Invalid channel name '.$channel);
        }
    }

    /**
     * Ensure a socket_id is valid based on our specification.
     *
     * @param string $socketId The socket ID to validate
     *
     * @throws BroadcasttException If $socketId is invalid
     */
    private function validateSocketId($socketId)
    {
        if ($socketId !== null && ! preg_match('/\A\d+\.\d+\z/', $socketId)) {
            throw new BroadcasttException('Invalid socket ID '.$socketId);
        }
    }

    /**
     * Utility function used to create the curl object with common settings.
     *
     * @param string $domain
     * @param string $path
     * @param string $requestMethod
     * @param array $queryParams
     *
     * @throws BroadcasttException Throws exception if curl wasn't initialized correctly
     *
     * @return resource
     */
    private function createCurl($domain, $path, $requestMethod = 'GET', $queryParams = [])
    {
        $path = strtr($path, ['{appId}' => $this->appId]);

        // Create the signed signature...
        $signedQuery = $this->buildAuthQueryString($this->appSecret, $requestMethod, $path, $queryParams);

        $uri = $domain.$path.'?'.$signedQuery;

        $this->log('create_curl( {uri} )', ['uri' => $uri]);

        // Create or reuse existing curl handle
        if (! is_resource($this->curlHandler)) {
            $this->curlHandler = curl_init();
        }

        if ($this->curlHandler === false) {
            throw new BroadcasttException('Could not initialise cURL!');
        }

        $ch = $this->curlHandler;

        // curl handle is not reusable unless reset
        if (function_exists('curl_reset')) {
            curl_reset($ch);
        }

        // Set cURL opts and execute request
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Expect:',
            'X-Library: broadcastt-php '.self::$VERSION,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        if ($requestMethod === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
        } elseif ($requestMethod === 'GET') {
            curl_setopt($ch, CURLOPT_POST, 0);
        } // Otherwise let the user configure it

        // Set custom curl options
        if (! empty($this->curlOptions)) {
            foreach ($this->curlOptions as $option => $value) {
                curl_setopt($ch, $option, $value);
            }
        }

        return $ch;
    }

    /**
     * Utility function to execute curl and create capture response information.
     *
     * @param $ch resource
     *
     * @return array
     */
    private function execCurl($ch)
    {
        $response = [];

        $response['body'] = curl_exec($ch);
        $response['status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response['body'] === false || $response['status'] < 200 || 400 <= $response['status']) {
            $this->log('exec_curl error: {error}', ['error' => curl_error($ch)], LogLevel::ERROR);
        }

        $this->log('exec_curl response: {response}', ['response' => print_r($response, true)]);

        return $response;
    }

    /**
     * Build the URI.
     *
     * @return string
     * @throws BroadcasttException
     */
    private function buildUri()
    {
        if (preg_match('/^http[s]?\:\/\//', $this->host) !== false) {
            throw new BroadcasttException("Invalid host value. Host must not start with http or https.");
        }

        return $this->scheme.'://'.$this->host.':'.$this->port;
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

        $stringToSign = "$requestMethod\n".$requestPath."\n".self::arrayImplode('=', '&', $params);

        $authSignature = hash_hmac('sha256', $stringToSign, $this->getAppSecret(), false);

        $params['auth_signature'] = $authSignature;
        ksort($params);

        $authQueryString = self::arrayImplode('=', '&', $params);

        return $authQueryString;
    }

    /**
     * Implode an array with the key and value pair giving
     * a glue, a separator between pairs and the array
     * to implode.
     *
     * @param string $glue The glue between key and value
     * @param string $separator Separator between pairs
     * @param array|string $array The array to implode
     *
     * @return string The imploded array
     */
    public static function arrayImplode($glue, $separator, $array)
    {
        if (! is_array($array)) {
            return $array;
        }

        $string = [];
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $val = implode(',', $val);
            }
            $string[] = "{$key}{$glue}{$val}";
        }

        return implode($separator, $string);
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
     * @throws BroadcasttException Throws exception if $channels is an array of size 101 or above or $socketId is invalid
     *
     * @return bool|array
     */
    public function trigger($channels, $name, $data, $socketId = null, $jsonEncoded = false)
    {
        if (is_string($channels) === true) {
            $channels = [$channels];
        }

        $this->validateChannels($channels);
        $this->validateSocketId($socketId);

        $queryParams = [];

        $path = $this->basePath.'/event';

        $dataEncoded = $jsonEncoded ? $data : json_encode($data);

        // json_encode might return false on failure
        if (! $dataEncoded) {
            $this->log('Failed to perform json_encode on the the provided data: {error}', [
                'error' => print_r($data, true),
            ], LogLevel::ERROR);
        }

        $postParams = [];
        $postParams['name'] = $name;
        $postParams['data'] = $dataEncoded;
        $postParams['channels'] = $channels;

        if ($socketId !== null) {
            $postParams['socket_id'] = $socketId;
        }

        $postValue = json_encode($postParams);

        $queryParams['body_md5'] = md5($postValue);

        $ch = $this->createCurl($this->buildUri(), $path, 'POST', $queryParams);

        $this->log('trigger POST: {postValue}', ['postValue' => $postValue]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postValue);

        $response = $this->execCurl($ch);

        if ($response['status'] === 200) {
            return true;
        }

        return false;
    }

    /**
     * Trigger multiple events at the same time.
     *
     * @param array $batch [optional] An array of events to send
     * @param bool $encoded [optional] Defines if the data is already encoded
     *
     * @throws BroadcasttException Throws exception if curl wasn't initialized correctly
     *
     * @return array|bool|string
     */
    public function triggerBatch($batch = [], $encoded = false)
    {
        $queryParams = [];

        $path = $this->basePath.'/events';

        if (! $encoded) {
            foreach ($batch as $key => $event) {
                if (! is_string($event['data'])) {
                    $batch[$key]['data'] = json_encode($event['data']);
                }
            }
        }

        $postParams = [];
        $postParams['batch'] = $batch;

        $postValue = json_encode($postParams);

        $queryParams['body_md5'] = md5($postValue);

        $ch = $this->createCurl($this->buildUri(), $path, 'POST', $queryParams);

        $this->log('trigger POST: {postValue}', ['postValue' => $postValue]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postValue);

        $response = $this->execCurl($ch);

        if ($response['status'] === 200) {
            return true;
        }

        return false;
    }

    /**
     * GET arbitrary REST API resource using a synchronous http client.
     * All request signing is handled automatically.
     *
     * @param string $path Path excluding /apps/APP_ID
     * @param array $params API params (see https://broadcastt.xyz/docs/References-‐-Rest-API)
     *
     * @throws BroadcasttException Throws exception if curl wasn't initialized correctly
     *
     * @return array|bool See Broadcastt API docs
     */
    public function get($path, $params = [])
    {
        if (substr($path, 0, strlen($this->basePath)) === $this->basePath) {
            $path = $this->basePath.$path;
        }

        $ch = $this->createCurl($this->buildUri(), $path, 'GET', $params);

        $response = $this->execCurl($ch);

        if ($response['status'] === 200) {
            $response['result'] = json_decode($response['body'], true);

            return $response;
        }

        return false;
    }

    /**
     * Creates a socket signature.
     *
     * @param string $channel
     * @param string $socketId
     * @param string $customData
     *
     * @throws BroadcasttException Throws exception if $channel is invalid or above or $socketId is invalid
     *
     * @return string Json encoded authentication string.
     */
    public function privateAuth($channel, $socketId, $customData = null)
    {
        $this->validateChannel($channel);
        $this->validateSocketId($socketId);

        if ($customData) {
            $signature = hash_hmac('sha256', $socketId.':'.$channel.':'.$customData, $this->appSecret, false);
        } else {
            $signature = hash_hmac('sha256', $socketId.':'.$channel, $this->appSecret, false);
        }

        $signature = ['auth' => $this->appKey.':'.$signature];
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
     * @throws BroadcasttException Throws exception if $channel is invalid or above or $socketId is invalid
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
        $this->host = $cluster.self::$SLD;
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
     * @return string
     */
    public function getAppId()
    {
        return $this->appId;
    }

    /**
     * @return string
     */
    public function getAppKey()
    {
        return $this->appKey;
    }

    /**
     * @return string
     */
    public function getAppSecret()
    {
        return $this->appSecret;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @param string $host
     */
    public function setHost($host)
    {
        $this->host = $host;
    }

    /**
     * @return string
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * @param string $scheme
     */
    public function setScheme($scheme)
    {
        $this->scheme = $scheme;
    }

    /**
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @param int $port
     */
    public function setPort($port)
    {
        $this->port = $port;
    }

    /**
     * @return string
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    /**
     * @param string $basePath
     */
    public function setBasePath($basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * @return int
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    /**
     * @return array
     */
    public function getCurlOptions()
    {
        return $this->curlOptions;
    }

    /**
     * @param array $curlOptions
     */
    public function setCurlOptions(array $curlOptions)
    {
        $this->curlOptions = $curlOptions;
    }

}
