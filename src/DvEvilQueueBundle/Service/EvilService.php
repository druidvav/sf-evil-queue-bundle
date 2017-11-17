<?php
namespace DvEvilQueueBundle\Service;

use Doctrine\DBAL\Connection;
use DvEvilQueueBundle\Service\Caller\Request;

class EvilService
{
    protected $conn;

    public function __construct(Connection $connection)
    {
        $this->conn = $connection;
    }

    public function execute(Request $request)
    {
        $client = new ApiClient();
        return $client->call($request);
    }

    public function beginTransaction()
    {
        $this->conn->beginTransaction();
    }

    public function commit()
    {
        $this->conn->commit();
    }

    public function enqueue(Request $request, $queueName = 'general', $priority = 0)
    {
        $this->conn->insert('xmlrpc_queue', [
            'name' => $queueName,
            'priority' => $priority,
            'protocol' => $request->getProtocol(),
            'url' => $request->getUrl(),
            'method' => $request->getMethod(),
            'request_param' => serialize($request->getRequestParam()),
            'create_date' => date('Y-m-d H:i:s'),
            'request_timeout' => $request->getRequestTimeout(),
        ]);
        return $this->conn->lastInsertId();
    }

    public function deleteFromQueue($requestId)
    {
        $this->conn->delete('xmlrpc_queue', [ 'id' => $requestId ]);
    }

    public function existsQueue($queueName)
    {
        return $this->conn->fetchColumn('select id from xmlrpc_queue where name = :name limit 1', [ 'name' => $queueName ]) > 0;
    }

    public function deleteQueue($queueName)
    {
        $this->conn->delete('xmlrpc_queue', [ 'name' => $queueName ]);
    }
}