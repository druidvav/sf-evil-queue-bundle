<?php
namespace DvEvilQueueBundle\Exception;

class ApiServiceException extends \Exception
{
    protected $output = '';

    public function getOutput()
    {
        return $this->output;
    }

    public function setOutput($output): ApiServiceException
    {
        $this->output = $output;
        return $this;
    }

    public function getResponseForTable()
    {
        return [
            'status' => 'error',
            'response' => $this->message,
        ];
    }
}