<?php
namespace DvEvilQueueBundle\Command;

use Doctrine\DBAL\DBALException;
use Druidvav\EssentialsBundle\ConsoleWorkerManager;
use DvEvilQueueBundle\Service\EvilService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class QueueCommand extends Command
{
    /** @var EvilService */
    protected $evil;

    /** @var LoggerInterface */
    protected $logger;

    /** @var KernelInterface */
    protected $kernel;

    protected $workers;
    protected $priorityWorkers;

    public function setEvil(EvilService $evil)
    {
        $this->evil = $evil;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function setKernel(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
    }

    public function setConfig($workers, $priorityWorkers)
    {
        $this->workers = $workers;
        $this->priorityWorkers = $priorityWorkers;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('dv:evil:queue');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        declare(ticks = 1);

        while (true) {
            try {
                $this->evil->fixAutoIncrement();
                break;
            } catch (DBALException $exception) {
                if (preg_match('/An exception occured while establishing a connection to figure out your platform version/', $exception->getMessage())) {
                    $this->logger->error('Error connecting to database, will try again', [
                        'exception' => $exception
                    ]);
                } else {
                    throw $exception;
                }
            }
            sleep(10);
        }

        $master = new ConsoleWorkerManager();
        $master->setLogger($this->logger);
        $master->setEnv($this->kernel->getEnvironment());
        $master->setConsole($this->kernel->getRootDir() . '/../bin/console');
        for ($i = 0; $i < $this->workers; $i++) {
            $master->addWorker("dv:evil:queue-runner {$i}", 1);
        }
        for ($i = 0; $i < $this->priorityWorkers; $i++) {
            $master->addWorker("dv:evil:queue-runner --priority {$i}", 1);
        }
        $master->start();
    }
}
