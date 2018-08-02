<?php
namespace DvEvilQueueBundle\Service;

use DvEvilQueueBundle\Exception\ApiClientException;
use DvEvilQueueBundle\Service\Caller\Request;
use Zend\Http\Client\Exception\RuntimeException;
use Zend\Http\Request as HttpRequest;
use Zend\XmlRpc\Client as XmlRpcClient;
use Zend\Http\Client as HttpClient;
use Zend\XmlRpc\Client\Exception\FaultException;

class ApiClient
{
    public function call(Request $request)
    {
        try {
            if ($request->isHttp()) {
                return $this->callHttp($request);
            } elseif ($request->isJsonRpc()) {
                return $this->callJsonRpc($request);
            } else {
                return $this->callXmlRpc($request);
            }
        } catch (RuntimeException $e) {
            throw new ApiClientException($e->getMessage(), $e->getCode(), $e);
        } catch (FaultException $e) {
            throw new ApiClientException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function callXmlRpc(Request $request)
    {
        $client = new XmlRpcClient($request->getUrl());
        $client->setSkipSystemLookup();
        $client->getHttpClient()->setOptions([ 'timeout' => $request->getRequestTimeout() ]);
        $response = $client->call($request->getMethod(), $request->getRequestParam());
        return [
            'status' => is_array($response) && array_key_exists('status', $response) ? $response['status'] : 'error',
            'last_output' => $client->getLastResponse() ? $client->getLastResponse()->getReturnValue() : '',
            'response' => $response
        ];
    }

    protected function callHttp(Request $request)
    {
        $client = new HttpClient($request->getUrl(), [ 'timeout' => $request->getRequestTimeout() ]);

        $params = $request->getRequestParam();
        if ($request->getMethod()) {
            $client->setMethod($request->getMethod());
        }
        if (!empty($params['headers'])) {
            $client->setHeaders($params['headers']);
        }
        if (!empty($params['cookies'])) {
            foreach ($params['cookies'] as $name => $value) {
                $client->addCookie($name, $value);
            }
        }
        if (!empty($params['body'])) {
            $client->setRawBody($params['body']);
        }
        $response = $client->send();

        return [
            'status' => $response->getStatusCode() == 200 ? 'ok' : 'error',
            'last_output' => $response->getBody() ? $response->getBody() : '',
            'response' => [
                'status' => $response->getStatusCode(),
                'message' => $response->getReasonPhrase(),
            ]
        ];
    }

    protected function callJsonRpc(Request $request)
    {
        $client = new HttpClient($request->getUrl(), [ 'timeout' => $request->getRequestTimeout() ]);
        $client->setMethod(HttpRequest::METHOD_POST);
        $client->setHeaders([
            'Content-Type' => 'application/json; charset=utf-8'
        ]);

        $params = $request->getRequestParam();
        if (!empty($params['headers'])) {
            $client->setHeaders($params['headers']);
            unset($params['headers']);
        }

        $id = uniqid(null, true);
        $client->setRawBody(json_encode([
            "jsonrpc" => "2.0",
            "id" => $id,
            "method" => $request->getMethod(),
            "params" => empty($params) ? [] : $params
        ]));

        $response = $client->send();

        $decodedResponse = @json_decode($response->getBody(), true);
        if (!is_array($decodedResponse)) {
            return [
                'status' => 'error',
                'message' => 'Invalid response. The HTTP body should be a valid JSON.',
                'last_output' => $response->getBody() ? $response->getBody() : '',
            ];
        }

        $hasStatus = array_key_exists('result', $decodedResponse)
            && is_array($decodedResponse['result'])
            && array_key_exists('status', $decodedResponse['result']);

        return [
            'status' => $hasStatus ? $decodedResponse['result']['status'] : 'error',
            'response' => $decodedResponse,
            'last_output' => $response->getBody() ? $response->getBody() : '',
        ];
    }
}