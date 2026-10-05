<?php
/**
 * This file is part of the netsuitephp/netsuite-php library.
 *
 * @package    ryanwinchester/netsuite-php
 * @author     Ryan Winchester <fungku@gmail.com>
 * @copyright  Copyright (c) Ryan Winchester
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 * @link       https://github.com/netsuitephp/netsuite-php
 * created:    2015-01-22  1:04 PM
 * modified:   2026-10-05 by Max Dernovyi: REST transport routing
 */

namespace NetSuite;

use NetSuite\Classes\ApplicationInfo;
use NetSuite\Classes\GetDataCenterUrlsRequest;
use NetSuite\Classes\Preferences;
use NetSuite\Classes\SearchPreferences;
use NetSuite\Classes\TokenPassport;
use NetSuite\Classes\TokenPassportSignature;
use NetSuite\Rest\Auth\OAuth2Authenticator;
use NetSuite\Rest\Auth\TbaAuthenticator;
use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Dispatcher;
use NetSuite\Rest\Handler\HandlerFactory;
use NetSuite\Rest\Http\CallRecorder;
use NetSuite\Rest\Http\CurlTransport;
use NetSuite\Rest\Http\RestClient;
use NetSuite\Rest\Http\TransportInterface;
use NetSuite\Rest\LastCall;
use Psr\Log\LoggerInterface;
use SoapClient;
use SoapHeader;

class NetSuiteClient
{
    /** WSDL version used by the SOAP fallback when a rest config has no `endpoint`. */
    private const DEFAULT_ENDPOINT = '2025_2';

    /**
     * @var array
     */
    private $config;
    /**
     * @var SoapClient
     */
    private $client;
    /**
     * @var array
     */
    private $clientOptions = [];
    /**
     * @var array
     */
    private $soapHeaders = [];
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;
    /**
     * @var RestConfig|null null in soap mode
     */
    private $restConfig;
    /**
     * @var Dispatcher|null
     */
    private $dispatcher;
    /**
     * @var RestClient|null
     */
    private $restClient;
    /**
     * @var LastCall|null
     */
    private $lastCall;
    /**
     * @var bool
     */
    private $inSoapFallback = false;

    /**
     * @param array|null $config
     * @param array $options
     * @param SoapClient|null $client
     * @param \Psr\Log\LoggerInterface|null $logger
     */
    public function __construct(
        ?array $config = null,
        array $options = [],
        ?SoapClient $client = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config ?? self::getEnvConfig();

        $this->validateConfig($this->config);
        $this->clientOptions = $options;

        if (RestConfig::transportOf($this->config) === RestConfig::TRANSPORT_REST) {
            $this->restConfig = RestConfig::fromArray($this->config);
            // The SOAP fallback needs these; rest configs may omit them.
            if (empty($this->config['endpoint'])) {
                $this->config['endpoint'] = self::DEFAULT_ENDPOINT;
            }
            if (empty($this->config['host'])) {
                $this->config['host'] = 'https://'.$this->restConfig->host().'.suitetalk.api.netsuite.com';
            }
        }

        if (isset($client)) {
            $this->client = $client;
        }

        $this->logger = $logger ?? new Logger(
            !empty($this->config['log_path']) ? $this->config['log_path'] : null,
            !empty($this->config['log_format']) ? $this->config['log_format'] : Logger::DEFAULT_LOG_FORMAT,
            !empty($this->config['log_dateformat']) ? $this->config['log_dateformat'] : Logger::DEFAULT_DATE_FORMAT
        );
    }

    /**
     * Set the data center URL for the configured NetSuite account
     *
     * @param array $config
     *
     * @return void
     */
    public function setDataCenterUrl(array $config)
    {
        $params = new GetDataCenterUrlsRequest();
        $params->account = $config['account'];
        $result = $this->getDataCenterUrls($params)->getDataCenterUrlsResult;
        $domain = $result->dataCenterUrls->webservicesDomain;
        $dataCenterUrl = $domain.'/services/NetSuitePort_'.$config['endpoint'];
        $this->soapClient()->__setLocation($dataCenterUrl);
    }

    /**
     * Create a configuration array by inspecting the $_ENV superglobal.
     *
     * @return array
     */
    public static function getEnvConfig()
    {
        $config = [
            'endpoint'           => getenv('NETSUITE_ENDPOINT') ?: '2025_2',
            'host'               => getenv('NETSUITE_HOST') ?: 'https://webservices.sandbox.netsuite.com',
            'email'              => getenv('NETSUITE_EMAIL'),
            'password'           => getenv('NETSUITE_PASSWORD'),
            'role'               => getenv('NETSUITE_ROLE') ?: '3',
            'account'            => getenv('NETSUITE_ACCOUNT'),
            'app_id'             => getenv('NETSUITE_APP_ID') ?: '4AD027CA-88B3-46EC-9D3E-41C6E6A325E2',
            'logging'            => getenv('NETSUITE_LOGGING'),
            'log_path'           => getenv('NETSUITE_LOG_PATH'),
            'log_format'         => getenv('NETSUITE_LOG_FORMAT'),
            'log_dateformat'     => getenv('NETSUITE_LOG_DATEFORMAT'),
        ];

        // These config keys aren't required by all users, but if they are
        // defined in the config array, then they must be correct, thus we
        // will omit ones that have been left empty in the .env file.
        $optKeys = [
            'NETSUITE_CONSUMER_KEY'    => 'consumerKey',
            'NETSUITE_CONSUMER_SECRET' => 'consumerSecret',
            'NETSUITE_TOKEN_KEY'       => 'token',
            'NETSUITE_TOKEN_SECRET'    => 'tokenSecret',
            'NETSUITE_HASH_TYPE'       => 'signatureAlgorithm',
            'NETSUITE_TRANSPORT'             => 'transport',
            'NETSUITE_OAUTH2_CLIENT_ID'      => 'oauth2ClientId',
            'NETSUITE_OAUTH2_CERTIFICATE_ID' => 'oauth2CertificateId',
            'NETSUITE_OAUTH2_PRIVATE_KEY'    => 'oauth2PrivateKey',
            'NETSUITE_OAUTH2_ALGORITHM'      => 'oauth2Algorithm',
        ];
        foreach ($optKeys as $optKey => $cfgKey) {
            if ($optVal = getenv($optKey)) {
                $config[$cfgKey] = $optVal;
            }
        }

        return $config;
    }

    /**
     * Make sure that this client object has at least the basic required
     * configuration values defined or else throw a runtime exception.
     *
     * @param array $config
     *
     * @return void
     */
    public function validateConfig(array $config)
    {
        if (RestConfig::transportOf($config) === RestConfig::TRANSPORT_REST) {
            RestConfig::fromArray($config);
            return;
        }

        $requiredParams = [
            'endpoint',
            'host',
            'account',
            'token',
            'tokenSecret',
            'consumerKey',
            'consumerSecret',
        ];
        foreach ($requiredParams as $key) {
            if (!isset($config[$key]) || empty($config[$key])) {
                throw new \RuntimeException('Config key missing: '.$key);
            }
        }
    }

    /**
     * Alternate way to instantiate the NetSuiteClient. This method is
     * superfluous now that the constructor will intelligently look for ENV
     * configuration when it isn't given explicit configuration. This static
     * method is retained for compatibility with those users who might
     * currently be using this method.
     *
     * This method will be removed in some future version.
     *
     * @deprecated
     *
     * @param array $options
     * @param \SoapClient $client
     *
     * @return \NetSuite\NetSuiteClient
     */
    public static function createFromEnv(
        array $options = [],
        ?\SoapClient $client = null
    ) {
        $config = self::getEnvConfig();

        return new static($config, $options, $client);
    }

    /**
     * Make the SOAP call, or the REST call in rest mode.
     *
     * @param string $operation
     * @param mixed $parameter
     * @return mixed
     */
    protected function makeSoapCall($operation, $parameter)
    {
        if ($this->restConfig === null || $this->inSoapFallback) {
            return $this->callSoap($operation, $parameter);
        }

        $soapHeaders = array_keys(array_diff_key($this->soapHeaders, ['tokenPassport' => true]));
        return $this->getDispatcher()->dispatch($operation, $parameter, $soapHeaders);
    }

    /**
     * Make the SOAP call!
     *
     * @param string $operation
     * @param mixed $parameter
     * @return mixed
     */
    private function callSoap($operation, $parameter)
    {
        $this->fixWtfCookieBug();
        $this->addHeader('tokenPassport', $this->createTokenPassportFromConfig($this->config));

        try {
            $response = $this->soapClient()->__soapCall($operation, [$parameter], null, $this->soapHeaders);
            $this->logSoapCall($operation);
            return $response;
        } catch (\Exception $e) {
            $this->logSoapCall($operation);
            throw $e;
        }
    }

    private function getDispatcher(): Dispatcher
    {
        if ($this->dispatcher === null) {
            $fallback = null;
            if ($this->restConfig->hasTbaKeys()) {
                $fallback = function ($operation, $parameter) {
                    $this->inSoapFallback = true;
                    try {
                        return $this->callSoap($operation, $parameter);
                    } finally {
                        $this->inSoapFallback = false;
                    }
                };
            }
            $restClient = function () {
                return $this->getRestClient();
            };
            $this->dispatcher = new Dispatcher(
                $this->createRestHandlers($restClient),
                $fallback,
                $this->getLastCall(),
                $this->logger
            );
        }
        return $this->dispatcher;
    }

    private function getRestClient(): RestClient
    {
        if ($this->restClient === null) {
            $transport = $this->createRestTransport($this->restConfig);
            $auth = $this->restConfig->authType() === RestConfig::AUTH_OAUTH2
                ? OAuth2Authenticator::fromConfig($this->restConfig, $transport)
                : TbaAuthenticator::fromConfig($this->restConfig);
            $this->restClient = new RestClient(
                $this->restConfig,
                $transport,
                $auth,
                $this->logger,
                null,
                $this->getLastCall()->getRecorder()
            );
            $this->restClient->setLogging(!empty($this->config['logging']));
        }
        return $this->restClient;
    }

    private function getLastCall(): LastCall
    {
        if ($this->lastCall === null) {
            $this->lastCall = new LastCall(new CallRecorder(), function () {
                return $this->client;
            });
        }
        return $this->lastCall;
    }

    /**
     * @param callable(): RestClient $restClient
     * @return array<string, callable> operation => lazy REST handler
     */
    protected function createRestHandlers(callable $restClient): array
    {
        return HandlerFactory::create($restClient, $this->logger);
    }

    protected function createRestTransport(RestConfig $config): TransportInterface
    {
        return new CurlTransport($config->timeout());
    }

    /**
     * Create the options array.
     *
     * @param array $config
     * @param array $overrides
     * @return array
     */
    private function createOptions($config, $overrides = [])
    {
        return array_merge([
            'classmap' => require __DIR__."/includes/classmap.php",
            'trace' => 1,
            'connection_timeout' => 5,
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'location' => $config['host']."/services/NetSuitePort_".$config['endpoint'],
            'keep_alive' => false,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            'user_agent' => "PHP-SOAP/".phpversion()." + ryanwinchester/netsuite-php",
        ], $overrides);
    }

    /**
     * Build the WSDL address from the config.
     *
     * @param array $config
     * @return string
     */
    private function createWsdl($config)
    {
        return $config['host'].'/wsdl/v'.$config['endpoint'].'_0/netsuite.wsdl';
    }

    /**
     * Create the TokenPassport.
     *
     * @param array $config
     * @return TokenPassport
     */
    private function createTokenPassportFromConfig($config)
    {
        $tokenPassport = new TokenPassport();
        $tokenPassport->account = $config['account'];
        $tokenPassport->consumerKey = $config['consumerKey'];
        $tokenPassport->token = $config['token'];
        $tokenPassport->nonce = $this->generateTokenPassportNonce();
        $tokenPassport->timestamp = time();

        $signatureAlgorithm = isset($config['signatureAlgorithm']) ? $config['signatureAlgorithm'] : 'sha256';

        $tokenSignature = new TokenPassportSignature();
        $tokenSignature->_ = $this->computeTokenPassportSignature(
            $config['account'],
            $config['consumerKey'],
            $config['consumerSecret'],
            $config['token'],
            $config['tokenSecret'],
            $tokenPassport->nonce,
            $tokenPassport->timestamp,
            $signatureAlgorithm
        );
        $tokenSignature->algorithm = 'HMAC_' . strtoupper($signatureAlgorithm);
        $tokenPassport->signature = $tokenSignature;

        return $tokenPassport;
    }

    /**
     * Add a header by name.
     *
     * @param string $header
     * @param mixed $value
     */
    public function addHeader($header, $value)
    {
        $this->soapHeaders[$header] = new SoapHeader("ns", $header, $value);
    }

    /**
     * Remove a header by name.
     *
     * @param string $header
     */
    public function clearHeader($header)
    {
        unset($this->soapHeaders[$header]);
    }

    /**
     * Set the application id.
     *
     * @param string $appId
     */
    public function setApplicationInfo($appId = null)
    {
        $applicationInfo = new ApplicationInfo();
        $applicationInfo->applicationId = $appId;
        $this->addHeader("applicationInfo", $applicationInfo);
    }

    /**
     * Set preferences header.
     *
     * @param bool $warningAsError
     * @param bool $disableMandatoryCustomFieldValidation
     * @param bool $disableSystemNotesForCustomFields
     * @param bool $ignoreReadOnlyFields
     */
    public function setPreferences(
        $warningAsError = false,
        $disableMandatoryCustomFieldValidation = false,
        $disableSystemNotesForCustomFields = false,
        $ignoreReadOnlyFields = false
    ) {
        $preferences = new Preferences();
        $preferences->warningAsError = $warningAsError;
        $preferences->disableMandatoryCustomFieldValidation = $disableMandatoryCustomFieldValidation;
        $preferences->disableSystemNotesForCustomFields = $disableSystemNotesForCustomFields;
        $preferences->ignoreReadOnlyFields = $ignoreReadOnlyFields;
        $this->addHeader("preferences", $preferences);
    }

    /**
     * Clear preferences header.
     */
    public function clearPreferences()
    {
        $this->clearHeader("preferences");
    }

    /**
     * Set the search preferences header.
     *
     * @param bool $bodyFieldsOnly
     * @param int $pageSize
     * @param bool $returnSearchColumns
     */
    public function setSearchPreferences($bodyFieldsOnly = true, $pageSize = 50, $returnSearchColumns = true)
    {
        $preferences = new SearchPreferences();
        $preferences->bodyFieldsOnly = $bodyFieldsOnly;
        $preferences->pageSize = $pageSize;
        $preferences->returnSearchColumns = $returnSearchColumns;

        $this->addHeader("searchPreferences", $preferences);
    }

    /**
     * Clear the search preferences.
     */
    public function clearSearchPreferences()
    {
        $this->clearHeader("searchPreferences");
    }

    /**
     * SoapClient apparently always sends the JSESSIONID cookie.
     * So we'll just un-set it to prevent this.
     */
    private function fixWtfCookieBug()
    {
        $this->soapClient()->__setCookie('JSESSIONID');
    }

    /**
     * Get the current soap client; in rest mode, the last-call object with the same __getLast*() accessors.
     *
     * @return \SoapClient|LastCall
     * @throws \SoapFault
     */
    public function getClient()
    {
        if ($this->restConfig !== null) {
            return $this->getLastCall();
        }
        return $this->getSoapClient();
    }

    /**
     * The client for SOAP calls; soap mode keeps going through getClient(), as upstream does.
     *
     * @return \SoapClient
     * @throws \SoapFault
     */
    private function soapClient()
    {
        return $this->restConfig === null ? $this->getClient() : $this->getSoapClient();
    }

    /**
     * @return \SoapClient
     * @throws \SoapFault
     */
    private function getSoapClient()
    {
        if (!isset($this->client)) {
            $options = $this->createOptions($this->config, $this->clientOptions);
            $wsdl = $this->createWsdl($this->config);
            $this->client = new SoapClient($wsdl, $options);
        }
        if (isset($this->config['host']) && $this->config['host'] == 'https://webservices.netsuite.com') {
            // Fetch the data center URL for this account because the user
            // provided the legacy webservices URL.
            unset($this->config['host']);
            $this->setDataCenterUrl($this->config);
        }
        return $this->client;
    }

    /**
     * Turn request logging on or off.
     *
     * @param bool $on
     */
    public function logRequests($on = true)
    {
        $this->config['logging'] = $on;

        if ($this->restClient !== null) {
            $this->restClient->setLogging((bool) $on);
        }
    }

    /**
     * Set the logging path.
     *
     * @param string $logPath
     */
    public function setLogPath($logPath)
    {
        $this->config['log_path'] = $logPath;

        if ($this->logger instanceof Logger) {
            $this->logger->setPath($logPath);
        }
    }

    /**
     * Log the last SOAP call.
     *
     * @param string $operation
     */
    private function logSoapCall($operation)
    {
        if (isset($this->config['logging']) && $this->config['logging']) {
            $this->logger->info(
                Logger::getSoapCallRequestMessage($this->soapClient()) ?? '',
                ['operation' => $operation, 'type' => Logger::TYPE_REQUEST]
            );

            $this->logger->info(
                Logger::getSoapCallResponseMessage($this->soapClient()) ?? '',
                ['operation' => $operation, 'type' => Logger::TYPE_RESPONSE]
            );
        }
    }

    /**
     * Compute TokenPassport signature
     *
     * @param int|string $account
     * @param string $consumerKey
     * @param string $consumerKey
     * @param string $token
     * @param string $tokenSecret
     * @param string $nonce
     * @param int|string $timestamp
     * @param string $signatureAlgorithm
     * @return string
     */
    private function computeTokenPassportSignature($account, $consumerKey, $consumerSecret, $token, $tokenSecret, $nonce, $timestamp, $signatureAlgorithm)
    {
        $baseString = implode('&', [$account, $consumerKey, $token, $nonce, $timestamp]);
        $key = $consumerSecret . '&' . $tokenSecret;
        return base64_encode(hash_hmac($signatureAlgorithm, $baseString, $key, true));
    }

    /**
     * Generate random (or sufficiently enough so) string of characters
     */
    private function generateTokenPassportNonce($length = 32)
    {
        $noncePool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $key = '';
        for ($i = 0; $i < $length; $i++) {
            $key .= $noncePool[mt_rand(0, 61)];
        }
        return $key;
    }
}
