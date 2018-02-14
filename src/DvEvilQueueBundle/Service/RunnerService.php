<?php
namespace DvEvilQueueBundle\Service;

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

    protected static $defaultPause = 50000;
    protected static $waitingPause = 500000;
    protected static $triesTillBan = 29;
    protected static $banPeriod = '+10 minutes';
    protected static $maxRequestsByQueueName = 15;

    const LOCK_ID = 'evil';
    const LOCK_ID_INT = 57399031;
    const HOST_EXPR = 'SUBSTRING(:url from 1 for position(\'/\' in SUBSTRING(:url from 10)) + 8)';

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
        if ($requestsData = $this->getNextRequest()) {
            foreach ($requestsData as $requestData) {
                if (!$this->executeRequest($requestData)) {
                    usleep(self::$defaultPause);
                    return;
                }
            }
            usleep(self::$defaultPause);
        } else {
            $this->logger->debug("Nothing found, sleeping...");
            usleep(self::$waitingPause);
        }
    }

    protected function getNextRequest()
    {
        if (!$this->obtainLock()) {
            $this->logger->alert('Cannot obtain "evil" lock');
            return null;
        }
        $start = microtime(true);
        $queueName = $this->getNextQueueName();
        $requests = $this->getNextRequestsByQueueName($queueName);
        $requestStart = date('Y-m-d H:i:s');
        foreach ($requests as &$request) {
            $this->conn->update('xmlrpc_queue', [ 'last_request_start' => $requestStart, 'last_request_date' => null, 'next_request_date' => null ], [ 'id' => $request['id'] ]);
            $request['last_request_start'] = $requestStart;
        }
        $runtime = round((microtime(true) - $start) * 1000);
        if ($runtime > 1000) {
            $this->logger->alert('Slow job acquiring time', [ 'time' => $runtime . 'ms' ]);
        }
        if (!$this->releaseLock()) {
            $this->logger->alert('Cannot release "evil" lock');
        }
        $runtime = round((microtime(true) - $start) * 1000);
        $this->logger->debug("Got job: {$runtime}ms");
        return $requests;
    }

    protected function obtainLock()
    {
        $driver = $this->conn->getDriver()->getName();
        if ($driver == 'pdo_mysql') {
            $lockResult = $this->conn->fetchColumn('select GET_LOCK(:id, 5)', [ 'id' => self::LOCK_ID ]);
            return !empty($lockResult);
        } elseif ($driver == 'pdo_pgsql') {
            $this->conn->beginTransaction();
            $retries = 500; // FIXME Tune this
            do {
                usleep(25000); // FIXME Tune this
                $lockResult = $this->conn->fetchColumn('select pg_try_advisory_xact_lock(:id)', [ 'id' => self::LOCK_ID_INT ]);
                $retries--;
            } while (empty($lockResult) && $retries >= 0);
            if (empty($lockResult)) {
                $this->conn->commit();
            }
            return !empty($lockResult);
        } else {
            throw new \Exception('Unknown database driver: ' . $driver);
        }
    }

    protected function releaseLock()
    {
        $driver = $this->conn->getDriver()->getName();
        if ($driver == 'pdo_mysql') {
            $releaseResult = $this->conn->fetchColumn('select RELEASE_LOCK(:id)', [ 'id' => self::LOCK_ID ]);
            return !empty($releaseResult);
        } elseif ($driver == 'pdo_pgsql') {
            $this->conn->commit();
            return true;
        } else {
            throw new \Exception('Unknown database driver: ' . $driver);
        }
    }

    protected function getNextQueueName()
    {
        $conditions = $this->isPriority ? ' and q.priority > 0' : '';
        return $this->conn->fetchColumn("
            select q.name
            from xmlrpc_queue q
            left join xmlrpc_queue qprev on qprev.name = q.name and qprev.id < q.id
            left join xmlrpc_host_down h on h.host = " . str_replace(':url', 'q.url', self::HOST_EXPR) . "
            where
              qprev.id is null
              and (q.last_request_start is null or q.last_request_start <= q.last_request_date)
              and (q.next_request_date is null or q.next_request_date <= now())
              and (h.down_untill is null or h.down_untill < NOW())
              {$conditions}
            order by q.id asc limit 1
        ");
    }

    protected function getNextRequestsByQueueName($queueName)
    {
        return $this->conn->fetchAll(
            "select q.* from xmlrpc_queue q where q.name = :name order by q.id asc limit " . self::$maxRequestsByQueueName,
            [ 'name' => $queueName ]
        );
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

            $runtime = round((microtime(true) - $start) * 1000);
            if (in_array($status, [ 'ok', 'warning' ])) {
                $this->handleSuccess($request, $response, $runtime);
                $return = true;
            } else {
                $this->handleError($request, $response, $lastOutput);
                $return = false;
            }
            $this->resetFailCounter($request);
        } catch (Exception $e) {
            $status = 'exception';
            $this->handleError($request, [
                'status' => 'error',
                'type' => 'request error',
                'message' => $e->getMessage(),
            ]);
            $this->increaseFailCounter($request);
            $return = false;
        }
        $runtime = round((microtime(true) - $start) * 1000);
        $this->logger->debug("Query {$status}: {$runtime}ms");
        return $return;
    }

    protected function handleSuccess($request, $response, $runtime)
    {
        try {
            $request['tries']++;
            $request['last_response'] = json_encode($response, JSON_UNESCAPED_UNICODE);
            $request['last_request_date'] = date('Y-m-d H:i:s');
            $request['comment'] = $runtime . ($request['comment'] ? ' ' . $request['comment'] : '');
            unset($request['next_request_date']);
            unset($request['last_output']);
            if (!empty($request)) {
                $request['worker_code'] = $this->cWorker . ($this->isPriority ? ' (p)' : '');
                $this->conn->insert('xmlrpc_queue_complete', $request);
            }
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), [ 'exception' => $e ]);
        }
        $this->conn->delete('xmlrpc_queue', [ 'id' => $request['id'] ]);
    }

    protected function handleError($request, $response, $lastOutput = '')
    {
        $this->conn->update('xmlrpc_queue', [
            'tries' => $request['tries'] + 1,
            'last_output' => json_encode($lastOutput, JSON_UNESCAPED_UNICODE),
            'last_response' => json_encode($response, JSON_UNESCAPED_UNICODE),
            'last_request_date' => date('Y-m-d H:i:s'),
            'next_request_date' => date('Y-m-d H:i:s', time() + pow($request['tries'] + 1, 2.5)),
        ], [ 'id' => $request['id'] ]);
    }

    protected function resetFailCounter($request)
    {
        $host = $this->conn->fetchColumn('select ' . self::HOST_EXPR, [ 'url' => $request['url'] ]);
        $this->conn->executeUpdate('update xmlrpc_host_down set down_since = null, down_untill = null, fails = 0 where host = :host', [
            'host' => $host
        ]);
    }

    protected function increaseFailCounter($request)
    {
        $host = $this->conn->fetchColumn('select ' . self::HOST_EXPR, [ 'url' => $request['url'] ]);
        $hostRow = $this->conn->fetchAssoc('select * from xmlrpc_host_down where host = :host', [ 'host' => $host ]);
        if (empty($hostRow)) {
            $this->conn->executeUpdate('insert into xmlrpc_host_down (host, down_since, down_untill, fails) values (:host, now(), null, 1)', [ 'host' => $host ]);
        } else {
            $this->conn->executeUpdate('update xmlrpc_host_down set down_since = coalesce(down_since, now()), fails = fails + 1 where host = :host', [ 'host' => $host ]);
            if ($hostRow['fails'] > self::$triesTillBan) {
                $this->conn->executeUpdate('update xmlrpc_host_down set down_untill = :until, fails = 0 where host = :host', [
                    ':host' => $host,
                    'until' => date('Y-m-d H:i:s', strtotime(self::$banPeriod))
                ]);
            }
        }
    }
}
