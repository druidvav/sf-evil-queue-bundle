<?php
namespace DvEvilQueueBundle\Service\Caller;

class Request
{
    const PROTOCOL_XML_RPC = 'xmlrpc';
    const PROTOCOL_HTTP = 'http';
    const PROTOCOL_JSON_RPC = 'jsonrpc';

    protected $protocol;
    protected $url;
    protected $method;
    protected $requestParam;
    protected $requestTimeout = 30;

    public static function fromArray(array $request): Request
    {
        if ($request['protocol'] == self::PROTOCOL_HTTP) {
            $protocol = self::PROTOCOL_HTTP;
        } else if ($request['protocol'] == self::PROTOCOL_JSON_RPC) {
            $protocol = self::PROTOCOL_JSON_RPC;
        } else {
            $protocol = self::PROTOCOL_XML_RPC;
        }
        if (substr($request['request_param'], 0, 1) == '['
            || substr($request['request_param'], 0, 1) == '{') {
            $requestParam = json_decode($request['request_param'], true);
        } else {
            $requestParam = @unserialize($request['request_param']);
        }
        $object = new self($protocol, $request['url'], $request['method'], (array) $requestParam);
        if (!empty($request['request_timeout'])) $object->setTimeout($request['request_timeout']);
        return $object;
    }

    public function __construct($protocol, $url, $method, array $param)
    {
        $this->protocol = $protocol;
        $this->url = $url;
        $this->method = $method;
        $this->requestParam = $param;
    }

    public function setTimeout($timeout)
    {
        $this->requestTimeout = $timeout;
    }

    public function isXmlRpc(): bool
    {
        return $this->protocol == self::PROTOCOL_XML_RPC;
    }

    public function isHttp(): bool
    {
        return $this->protocol == self::PROTOCOL_HTTP;
    }

    public function isJsonRpc(): bool
    {
        return $this->protocol == self::PROTOCOL_JSON_RPC;
    }

    public function getProtocol()
    {
        return $this->protocol;
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function getRequestParam(): array
    {
        return $this->isHttp() ? $this->requestParam : array_values($this->requestParam);
    }

    public function getRequestTimeout(): int
    {
        return $this->requestTimeout;
    }
}