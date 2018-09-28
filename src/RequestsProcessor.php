<?php

/**
 * This file is part of the Ingram Micro Cloud Blue Connect SDK.
 *
 * @copyright (c) 2018. Ingram Micro. All Rights Reserved.
 */

namespace Connect;

/**
 * Class RequestsProcessor
 *      Process APS Connect QuickStart API Requests
 * @package Connect
 */
class RequestsProcessor
{
    private $ch;
    private $config;

    /**
     * RequestsProcessor constructor
     * @param mixed $config
     *      one of
     *          Config - A configuration object
     *          string - ConfigFile path (JSON with config object inside)
     *          array  - Part of bigger config in format of JSON-parsed array with configuration
     * @throws ConfigException
     * @throws ConfigPropertyInvalid
     * @throws ConfigPropertyMissed
     * @throws \ReflectionException
     */
    public function __construct($config)
    {
        $this->config = ($config instanceof Config) ? $config : new Config($config);
        $this->config->validate();
        Logger::get()->setLogLevel($this->config->logLevel);
    }

    /**
     * Send request to remote API
     * @param string $verb - GET | POST | PUT | DELETE | PATCH
     * @param string $path
     * @param string $body (optional)
     * @return string
     * @throws Exception
     */
    private function sendRequest($verb, $path, $body = null)
    {
        if (!isset($this->ch)) {
            $this->ch = \curl_init();
            curl_setopt($this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($this->ch, CURLOPT_HEADER, true);
            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, $this->config->timeout);
            curl_setopt($this->ch, CURLOPT_TIMEOUT, $this->config->timeout);
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, $this->config->sslVerifyHost ? 2 : 0);
        }

        $requestId = uniqid('api-request-');
        $uri = $this->config->apiEndpoint . $path;
        $headers = array();
        $headers[] = 'Authorization: ApiKey ' . $this->config->apiKey;
        $headers[] = 'Request-ID: ' . $requestId;
        $headers[] = 'Content-Type: application/json';

        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($this->ch, CURLOPT_URL, $uri);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $verb);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $body ? $body : '');


        $requestHeadersString = join("\n", $headers);

        Logger::get()->info("HTTP Request: $verb $uri");
        if ($requestHeadersString) {
            Logger::get()->debug("Request Headers:\n$requestHeadersString");
        }
        if ($body) {
            Logger::get()->debug("Request Body:\n$body");
        }

        $rawResponse = curl_exec($this->ch);
        $pos = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
        $responseHeadersString = rtrim(substr($rawResponse, 0, $pos));
        $response = substr($rawResponse, $pos);
        $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        $httpReason = explode(" ", explode("\n", $responseHeadersString, 2)[0], 2)[1];

        Logger::get()->info("HTTP Code: $httpReason");
        Logger::get()->debug("HTTP Response: " . $rawResponse);

        if ($httpCode >= 299) {
            if (isset($response) and '{' == $response[0]) {
                // Try to extract error if exists (JSON only)
                $errorObj = json_decode($response);
                $errorCode = (isset($errorObj->code)) ? $errorObj->code : $httpCode;
                $errorMessage = (isset($errorObj->type)) ? $errorObj->type . ': ' : '';
                $errorMessage .= (isset($errorObj->message)) ? $errorObj->message : '';

                throw new Exception($errorMessage, $errorCode, $errorObj);
            }

            throw new Exception(
                'HTTP Error code=' . $httpCode . ' ' . $response . PHP_EOL . curl_error($this->ch),
                $httpCode
            );
        }

        if (curl_errno($this->ch)) {
            throw new Exception(
                sprintf(
                    'Connection error: "%s". Was trying to connect to "%s".',
                    curl_error($this->ch) . PHP_EOL,
                    $uri
                ),
                500
            );
        }

        return $response;
    }

    /**
     * Parse JSON-based array-structure to tree of objects, recurrent calling
     * @param array $structure
     * @param string $className
     * @return array
     * @throws \ReflectionException
     */
    private function parse($structure, $className)
    {
        // handle Array
        if (substr($className, -2) === '[]') {

            $ret = array();
            foreach ($structure as $el) {
                $obj = $this->parse($el, substr($className, 0, -2));
                $ret[] = $obj;
            }

            return $ret;
        }

        // handle Map
        if (substr($className, -1) === '}') {
            $mapTo = null;
            if (preg_match('/{(\w*)}$/', $className, $m)) {
                $mapTo = $m[1];
                $className = substr($className, 0, -strlen($m[0]));
            }

            $ret = array();
            foreach ($structure as $key => $el) {
                $obj = $this->parse($el, $className);
                if ($mapTo) {
                    $key = $el[$mapTo];
                }
                $ret[$key] = $obj;
            }

            return $ret;
        }


        $class = '\\Connect\\' . $className;
        $obj = new $class;

        $ref = new \ReflectionClass($obj);
        $props = $ref->getProperties();

        foreach ($props as $prop) {
            if ($prop->getName() == 'requestProcessor') {
                $prop->setValue($obj, $this);
                continue;
            }

            if (isset($structure[$prop->getName()])) {
                $value = $structure[$prop->getName()];
                if ($prop->getDocComment()) {
                    $comment = trim($prop->getDocComment());

                    if (preg_match('/\@noparse/', $comment)) {
                        continue;
                    }

                    $m = null;
                    if (preg_match('/\@var\s+(\S+)/', $comment, $m)) {
                        $subClassName = $m[1];
                        switch ($subClassName) {
                            case 'int':
                                $value = ($value == '') ? null : (int)$value;
                                break;
                            case 'string':
                                break;
                            case 'bool':
                                $value = (bool)$value;
                                break;
                            default:
                                $value = $this->parse($value, $subClassName);
                                break;
                        }
                    }
                }
                $prop->setValue($obj, $value);
            }
        }

        return $obj;
    }

    /**
     * Process one request, abstract function
     * @param Request $req - request being processed
     * @returns string - returns activation message, optional
     * @throws Exception
     */
    public function processRequest($req)
    {
        throw new Exception('processRequest() method is not implemented, reqId=' . $req->id);
    }

    /**
     * Process all requests
     * @throws \Exception
     */
    public function process()
    {
        $reqlist = $this->listRequests(['status' => 'pending']);

        foreach ($reqlist as $req) {
            if ($this->config->products && !in_array($req->asset->product->id, $this->config->products)) {
                continue;
            }

            if ($req->status == 'pending') { // actually default filter is pending
                $processingResult = 'unknown';
                try {
                    Logger::get()->info("Starting processing of request ID=" . $req->id);

                    /** @noinspection PhpVoidFunctionResultUsedInspection */
                    $msg = $this->processRequest($req);
                    if (!$msg || is_string($msg)) {
                        $msg = new ActivationTileResponse($msg);
                    }

                    if ($msg instanceof ActivationTemplateResponse) {
                        $this->sendRequest('POST', '/requests/' . $req->id . '/approve',
                            '{"template_id": "' . $msg->templateid . '"}');
                        $processingResult = 'succeed (Activated using template ' . $msg->templateid . ')';
                    } else {
                        $this->sendRequest('POST', '/requests/' . $req->id . '/approve',
                            '{"activation_tile": "' . $msg->activationTile . '"}');
                        $processingResult = 'succeed (' . $msg->activationTile . ')';
                    }
                } /** @noinspection PhpRedundantCatchClauseInspection */
                catch (Inquire $e) {
                    // update parameters and move to inquire
                    $this->updateParameters($req, $e->params);
                    $this->sendRequest('POST', '/requests/' . $req->id . '/inquire', '{}');
                    $processingResult = 'inquire';
                } /** @noinspection PhpRedundantCatchClauseInspection */
                catch (Fail $e) {
                    // fail request
                    $this->sendRequest('POST', '/requests/' . $req->id . '/fail',
                        '{"reason": "' . $e->getMessage() . '"}');
                    $processingResult = 'fail';
                } /** @noinspection PhpRedundantCatchClauseInspection */
                catch (Skip $e) {
                    $processingResult = 'skip';
                }
                Logger::get()->info("Finished processing of request ID=" . $req->id . " result=" . $processingResult);
            }
        }
    }

    /**
     * List requests
     * @param array $filters Filter for listing key->value or key->array(value1, value2)
     * @return Request[]
     * @throws Exception
     * @throws \ReflectionException
     */
    public function listRequests($filters = null)
    {
        $query = '';
        $filters = $filters ? array_merge($filters) : array();

        if ($this->config->products) {
            $filters['product_id'] = $this->config->products;
        }

        if ($filters) {
            $query = http_build_query($filters);

            // process case when value for filter is array
            $query = '?' . preg_replace('/%5B[0-9]+%5D/simU', '', $query);
        }

        $body = $this->sendRequest('GET', '/requests' . $query);
        $structure = json_decode($body, true);

        return $this->parse($structure, 'Request[]');
    }

    /**
     * Update request parameters
     * @param Request $req - request being updated
     * @param Param[] $parray - array of parameters
     *      Example:
     *          array(
     *              $req->asset->params['param_a']->error('Unknown activation ID was provided'),
     *              $req->asset->params['param_b']->value('true'),
     *              new \Connect\Param('param_c', 'newValue')
     *          )
     * @throws Exception
     */
    public function updateParameters($req, $parray)
    {
        $plist = array();
        foreach ($parray as $p) {
            $parr = (array)$p;

            unset($parr['value_choices']);

            foreach ($parr as $k => $v) {
                if (!$v) {
                    unset($parr[$k]);
                }

                if ($k == 'value' && !$v) {
                    $parr[$k] = '';
                }
            }

            $plist[] = $parr;
        }

        $body = json_encode(array('asset' => array('params' => $plist)), JSON_PRETTY_PRINT);
        $this->sendRequest('PUT', '/requests/' . $req->id, $body);
    }

    /**
     * Gets Activation template for a given request
     * @param $templateId - ID of template requested
     * @param $request - ID of request or Request object
     * @return string - Rendered template
     * @throws Exception
     */
    public function renderTemplate($templateId, $request)
    {
        $query = ($request instanceof Request) ? $request->id : $request;
        return $this->sendRequest('GET', '/templates/' . $templateId . '/render?request_id=' . $query);
    }

}