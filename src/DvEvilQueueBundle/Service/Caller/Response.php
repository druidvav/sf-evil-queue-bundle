<?php
namespace DvEvilQueueBundle\Service\Caller;

class Response
{
    protected $output;
    protected $response;

    public function __construct($response, $output)
    {
        $this->output = $output;
        $this->response = $response;
    }

    public function getOutput()
    {
        return $this->output;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getResponseForTable()
    {
        if (is_array($this->response) && array_key_exists('status', $this->response) && $this->response['status'] == 'ok') {
            return $this->response;
        } else {
            return [
                'status' => 'ok',
                'response' => $this->response,
            ];
        }
    }
}