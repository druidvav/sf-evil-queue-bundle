<?php
/** @noinspection SqlRedundantOrderingDirection */

namespace DvEvilQueueBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DvEvilQueueBundle\Exception\ApiServiceException;
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
    protected $counter = 0;

    protected $restartAfter = 500;
    protected $usleepAfterRequest = 50000;
    protected $usleepAfterEmpty = 2000000;

    protected static $triesTillBan = 29;
    protected static $banPeriod = '+10 minutes';
    public static $maxRequestsByQueueName = 15;

    const LOCK_ID = 'evil';
    const LOCK_ID_INT = 57399031;
    const HOST_EXPR = 'SUBSTRING(:url from 1 for position(\'/\' in SUBSTRING(:url from 10)) + 8)';

    public function __construct(Connection $connection)
    {
        $this->conn = $connection;
    }

    public function setRestartAfter($value)
    {
        $this->restartAfter = $value;
    }

    public function setSleepTimes($afterRequest, $afterEmpty)
    {
        $this->usleepAfterRequest = $afterRequest;
        $this->usleepAfterEmpty = $afterEmpty;
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

    public function run()
    {
        declare(ticks = 1);

        $runtimeStart = time();
        $running = true;

        pcntl_signal(SIGINT, function () use (&$running) {
            $this->logger->info('Got SIGINT, stopping...');
            $running = false;
        });

        pcntl_signal(SIGTERM, function () use (&$running) {
            $this->logger->info('Got SIGTERM, stopping...');
            $running = false;
        });

        $tmpDir = sys_get_temp_dir();
        if ($this->getDebug() && !is_writable($tmpDir)) {
            throw new Exception('Temporary directory is not writable');
        }

        $this->logger->info('Worker started: #' . $this->cWorker);
        while ($running && $this->counter <= $this->restartAfter) {
            if ($requestsData = $this->getNextRequest()) {
                $ok = true;
                foreach ($requestsData as $requestData) {
                    if ($ok && $running) {
                        $ok = $this->executeRequest($requestData);
                    } else {
                        $this->unlockRequest($requestData);
                    }
                }
                usleep($this->usleepAfterRequest);
            } else {
                $this->logger->debug("Nothing found, sleeping...");
                usleep($this->usleepAfterEmpty);
            }
            if ($this->getDebug()) {
                $memoryUsage      = memory_get_usage();
                $runtime          = time() - $runtimeStart;
                $memoryPeakUsage  = memory_get_peak_usage();
                $stat = "Runtime: {$runtime}sec; Memory Usage: {$memoryUsage}b, peak: {$memoryPeakUsage}b";
                file_put_contents($tmpDir . '/evil_thread_' . $this->cWorker, $stat);
                $this->logger->debug($stat);
            }
            pcntl_signal_dispatch();
        }
        $this->logger->info('Worker stopped: #' . $this->cWorker);
    }

    protected function getNextRequest()
    {
        if (!$this->obtainLock()) {
            $this->logger->alert('Cannot obtain "evil" lock');
            return null;
        }
        $start = microtime(true);
        $queueName = $this->getNextQueueName();
        $rawRequests = $this->getNextRequestsByQueueName($queueName);
        $requestStart = date('Y-m-d H:i:s');
        $requests = [ ];
        foreach ($rawRequests as $request) {
            if (empty($request['last_request_start']) || (!empty($request['last_request_date']) && $request['last_request_start'] <= $request['last_request_date'])) {
                $request['last_request_start'] = $requestStart;
                $this->lockRequest($request);
                $requests[] = $request;
            } else {
                break;
            }
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
        $this->counter += sizeof($requests);
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
            $retries = 400; // FIXME Tune this
            do {
                usleep(25000); // FIXME Tune this
                $lockResult = $this->conn->fetchColumn('select pg_try_advisory_xact_lock(:id)', [ 'id' => self::LOCK_ID_INT ]);
                $retries--;
            } while (empty($lockResult) && $retries >= 0);
            if (empty($lockResult)) {
                $this->conn->commit();
                return false;
            } else {
                return true;
            }
        } else {
            throw new Exception('Unknown database driver: ' . $driver);
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
            throw new Exception('Unknown database driver: ' . $driver);
        }
    }

    protected function getNextQueueName()
    {
        $conditions = $this->isPriority ? ' and q.priority > 0' : '';
        return $this->conn->fetchColumn("
            select q.name
            from xmlrpc_queue q
            left join xmlrpc_host_down h on h.host = " . str_replace(':url', 'q.url', self::HOST_EXPR) . "
            where
              q.id in (select min(id) as id from xmlrpc_queue group by name)
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
        try {
            $client = new ApiClient();
            $response = $client->call(Request::fromArray($request));
            $runtime = round((microtime(true) - $start) * 1000);
            $this->handleSuccess($request, $response->getResponseForTable(), $runtime);
            $this->resetFailCounter($request);
            $status = 'ok';
            $return = true;
        } catch (ApiServiceException $e) {
            $status = 'error';
            $this->handleError($request, $e->getResponseForTable(), $e->getOutput());
            $return = false;
        } catch (Exception $e) {
            $status = 'exception';
            $this->handleError($request, [ 'status' => 'error', 'type' => 'request error', 'message' => $e->getMessage() ]);
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
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->error('Duplicated request result', [ 'request' => $request ]);
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

    protected function lockRequest($request)
    {
        $this->conn->update('xmlrpc_queue', [ 'last_request_start' => $request['last_request_start'], 'last_request_date' => null, 'next_request_date' => null ], [ 'id' => $request['id'] ]);
    }

    protected function unlockRequest($request)
    {
        $this->conn->update('xmlrpc_queue', [ 'last_request_start' => null ], [ 'id' => $request['id'] ]);
        $this->logger->debug('Unlocking ' . $request['id']);
    }

    protected function resetFailCounter($request)
    {
        $host = $this->conn->fetchColumn('select ' . self::HOST_EXPR, [ 'url' => $request['url'] ]);
        $this->conn->executeUpdate('update xmlrpc_host_down set down_since = null, down_untill = null, fails = 0 where host = :host and (fails <> 0 or down_untill is not null)', [
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
