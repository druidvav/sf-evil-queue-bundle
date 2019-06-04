<?php
namespace DvEvilQueueBundle\Service\Caller;

class Response
{
    protected $status;
    protected $output;
    protected $response;

    public function __construct($status, $response, $output)
    {
        $this->status = $status;
        $this->output = $output;
        $this->response = $response;
    }

    public function isOk()
    {
        return in_array($this->status, ['ok', 'warning']);
    }

    public function getStatus()
    {
        return $this->status;
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
        return [
            'status' => $this->status,
            'response' => $this->response,
        ];
    }
}