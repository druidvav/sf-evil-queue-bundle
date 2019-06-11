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
        if ($this->isOk()) {
            if (array_key_exists('status', $this->response)) {
                return $this->response;
            } else {
                return [
                    'status' => $this->status,
                    'response' => $this->response,
                ];
            }
        } else {
            return [
                'status' => $this->status,
                'response' => $this->response,
            ];
        }
    }
}