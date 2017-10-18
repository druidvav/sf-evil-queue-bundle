<?php
namespace DvEvilQueueBundle\Service;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use DvEvilQueueBundle\Service\Caller\Request;
use Exception;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerAwareTrait;

class RunnerService
{
    use LoggerAwareTrait;

    protected $conn;
    protected $debug = false;
    protected $cWorker = 0;
    protected $isPriority = 1;

    protected static $defaultPause = 200000;
    protected static $waitingPause = 1000000;
    protected static $triesTillBan = 29;
    protected static $banPeriod = '+1 hour';

    public function __construct(Connection $connection)
    {
        $this->conn = $connection;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    public function getDebug()
    {
        return $this->debug;
    }

    public function setWorker($cWorker, $isPriority)
    {
        $this->cWorker = $cWorker;
        $this->isPriority = $isPriority;
    }

    public function tick()
    {
        if ($requestData = $this->getNextRequest()) {
            $this->executeRequest($requestData);
            $pause = self::$defaultPause;
        } else {
            $this->logger->info("Nothing found, sleeping...");
            $pause = self::$waitingPause;
        }
        usleep($pause);
    }

    protected function getNextRequest()
    {
        $downHosts = [ ];
        $downHostsRaw = $this->conn->fetchAll('select host from xmlrpc_host_down where down_untill is not null and down_untill > NOW()');
        foreach ($downHostsRaw as $row) {
            $downHosts[] = $row['host'];
        }
        $conditions = '';
        if (!empty($downHosts)) $conditions .= " and SUBSTRING(q.url from 1 for locate('/', q.url, 10) - 1) not in (:downHosts)";
        if ($this->isPriority) $conditions .= ' and q.priority > 0';
        $lockResult = $this->conn->fetchColumn('select GET_LOCK(\'evil\', 5)');
        if (empty($lockResult)) {
            $this->logger->alert('Cannot obtain "evil" lock');
            return null;
        }
        $request = $this->conn->fetchAssoc("
            select q.*
            from xmlrpc_queue q
            left join xmlrpc_queue qprev on qprev.name = q.name and qprev.id < q.id
            where
              qprev.id is null
              and (q.last_request_start is null or q.last_request_start <= q.last_request_date)
              and (q.last_request_date is null or FROM_UNIXTIME(unix_timestamp(q.last_request_date) + pow(q.tries, 2.5)) < now())
              {$conditions}
              order by q.priority desc, q.id asc limit 1
        ", [
            'downHosts' => $downHosts
        ], [
            'downHosts' => Connection::PARAM_STR_ARRAY
        ]);
        if (!empty($request) && !empty($request['id'])) {
            $requestStart = date('Y-m-d H:i:s');
            $this->conn->update('xmlrpc_queue', [ 'last_request_start' => $requestStart, 'last_request_date' => null ], [ 'id' => $request['id'] ]);
            $request['last_request_start'] = $requestStart;
        } else {
            $request = null;
        }
        $releaseResult = $this->conn->fetchColumn('select RELEASE_LOCK(\'evil\')');
        if (empty($releaseResult)) {
            $this->logger->alert('Cannot release "evil" lock');
        }
        return $request;
    }

    protected function executeRequest($request)
    {
        $start = microtime(true);
        $client = new ApiClient();

        try {
            $response = $client->call(Request::fromArray($request));
            $status = $response['status'];
            $lastOutput = $response['last_output'];
            unset($response['last_output']);

            if (in_array($status, [ 'ok', 'warning' ])) {
                $this->handleSuccess($request, $response);
            } elseif (in_array($status, [ 'wait' ])) {
                $this->handleWaiting($request, $response, $lastOutput);
            } else {
                $this->handleError($request, $response, $lastOutput);
            }
            $this->resetFailCounter($request);

            $runtime = round(microtime(true) - $start, 3);
            $this->logger->info("Query {$status}: {$runtime}sec");
        } catch (Exception $e) {
            $this->handleError($request, [
                'status' => 'error',
                'type' => 'request error',
                'message' => $e->getMessage(),
            ]);
            $this->increaseFailCounter($request);
        }
    }

    protected function handleSuccess($request, $response)
    {
        try {
            $request['tries']++;
            $request['last_output'] = null;
            $request['last_response'] = json_encode($response, JSON_UNESCAPED_UNICODE);
            $request['last_request_date'] = date('Y-m-d H:i:s');
            if (!empty($request)) {
                $request['worker_code'] = $this->cWorker . ($this->isPriority ? ' (p)' : '');
                $this->conn->insert('xmlrpc_queue_complete', $request);
            }
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), [ 'exception' => $e ]);
        }
        $this->conn->delete('xmlrpc_queue', [ 'id' => $request['id'] ]);
    }

    protected function handleWaiting($request, $response, $lastOutput = '')
    {
        $this->conn->executeUpdate('UPDATE xmlrpc_queue SET tries = tries + 1, last_output = :last_output, last_response = :last_response, last_request_date = now() WHERE id = :id', [
            'id' => $request['id'],
            'last_output' => $lastOutput,
            'last_response' => json_encode($response, JSON_UNESCAPED_UNICODE),
        ]);
    }

    protected function handleError($request, $response, $lastOutput = '')
    {
        $this->conn->executeUpdate('UPDATE xmlrpc_queue SET tries = tries + 1, last_output = :last_output, last_response = :last_response, last_request_date = now() WHERE id = :id', [
            'id' => $request['id'],
            'last_output' => json_encode($lastOutput, JSON_UNESCAPED_UNICODE),
            'last_response' => json_encode($response, JSON_UNESCAPED_UNICODE),
        ]);
    }

    protected function resetFailCounter($request)
    {
        $host = $this->conn->fetchColumn('select SUBSTRING(:url from 1 for locate(\'/\', :url, 10) - 1)', [
            'url' => $request['url']
        ]);
        $this->conn->executeUpdate('update xmlrpc_host_down set down_since = null, down_untill = null, fails = 0 where host = :host', [
            'host' => $host
        ]);
    }

    protected function increaseFailCounter($request)
    {
        $host = $this->conn->fetchColumn('select SUBSTRING(:url from 1 for locate(\'/\', :url, 10) - 1)', [
            'url' => $request['url']
        ]);

        $hostRow = $this->conn->fetchAssoc('select * from xmlrpc_host_down where host = :host', [ 'host' => $host ]);
        if (empty($hostRow)) {
            $this->conn->executeUpdate('insert into xmlrpc_host_down (host, down_since, down_untill, fails) values (:host, now(), null, 1)', [ 'host' => $host ]);
        } else {
            $this->conn->executeUpdate('update xmlrpc_host_down set down_since = coalesce(down_since, now()), fails = fails + 1 where host = :host', [ 'host' => $host ]);
        }
        if ($hostRow['fails'] > self::$triesTillBan) {
            $this->conn->executeUpdate('update xmlrpc_host_down set down_untill = :until, fails = 0 where host = :host', [
                ':host' => $host,
                'until' => date('Y-m-d H:i:s', strtotime(self::$banPeriod))
            ]);
        }
    }
}
