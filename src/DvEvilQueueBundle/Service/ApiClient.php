<?php
namespace DvEvilQueueBundle\Service;

use DvEvilQueueBundle\Exception\ApiClientException;
use DvEvilQueueBundle\Exception\ApiServiceException;
use DvEvilQueueBundle\Service\Caller\Request;
use DvEvilQueueBundle\Service\Caller\Response;
use Zend\Http\Client\Exception\RuntimeException;
use Zend\Http\Request as HttpRequest;
use Zend\XmlRpc\Client as XmlRpcClient;
use Zend\Http\Client as HttpClient;
use Zend\XmlRpc\Client\Exception\FaultException;

class ApiClient
{
    /**
     * @param Request $request
     * @return Response
     * @throws ApiClientException
     * @throws ApiServiceException
     */
    public function call(Request $request): Response
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

    protected function callXmlRpc(Request $request): Response
    {
        $client = new XmlRpcClient($request->getUrl());
        $client->setSkipSystemLookup();
        $client->getHttpClient()->setOptions([ 'timeout' => $request->getRequestTimeout() ]);
        $response = $client->call($request->getMethod(), $request->getRequestParam());
        $output = $client->getLastResponse() ? $client->getLastResponse()->getReturnValue() : '';
        if (empty($response['status'])) {
            throw (new ApiServiceException('Unknown status'))->setOutput($output);
        } elseif ($response['status'] == 'error' || !empty($response['error_message_pretty']) || !empty($response['error_message'])) {
            if (!empty($response['error_message_pretty'])) {
                throw (new ApiServiceException($response['error_message_pretty']))->setOutput($output);
            } elseif (!empty($response['error_message'])) {
                throw (new ApiServiceException($response['error_message']))->setOutput($output);
            } else {
                throw (new ApiServiceException('Unknown error'))->setOutput($output);
            }
        } elseif (!empty($response['warning_message_pretty'])) {
            $response = $response['warning_message_pretty'];
        } elseif (!empty($response['status']) && array_key_exists('data', $response)) {
            $response = $response['data'];
        }
        return new Response($response, $output);
    }

    protected function callHttp(Request $request): Response
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
        if ($response->getStatusCode() != 200) {
            throw (new ApiServiceException('HTTP error: ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase()))->setOutput($response->getBody() ?: '');
        }
        return new Response([ ], $response->getBody() ?: '');
    }

    protected function callJsonRpc(Request $request): Response
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
            throw (new ApiServiceException('Invalid response. The HTTP body should be a valid JSON.'))->setOutput($response->getBody() ?: '');
        }
        if (!empty($decodedResponse['error'])) {
            throw (new ApiServiceException($decodedResponse['error']['message']))->setOutput($response->getBody() ?: '');
        }
        $decodedResponse = $decodedResponse['result'];
        if (!empty($decodedResponse['status']) && $decodedResponse['status'] == 'error') {
            throw (new ApiServiceException('Legacy error message, this situations should be avoided at any cost!'))->setOutput($response->getBody() ?: '');
        }
        if (!empty($decodedResponse['status']) && array_key_exists('data', $decodedResponse)) {
            $decodedResponse = $decodedResponse['data'];
        }
        return new Response($decodedResponse, $response->getBody() ?: '');
    }
}