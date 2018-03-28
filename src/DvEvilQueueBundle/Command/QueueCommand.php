<?php
namespace DvEvilQueueBundle\Command;

use Doctrine\DBAL\DBALException;
use Druidvav\EssentialsBundle\Command;
use Druidvav\EssentialsBundle\ConsoleWorkerManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueCommand extends Command
{
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
        
        $workersRegular = $this->getContainer()->getParameter('evil_queue.workers');
        $workersPriority = $this->getContainer()->getParameter('evil_queue.priority_workers');

        while (true) {
            try {
                $this->getContainer()->get('evil')->fixAutoIncrement();
                break;
            } catch (DBALException $exception) {
                if (preg_match('/An exception occured while establishing a connection to figure out your platform version/', $exception->getMessage())) {
                    $this->getContainer()->get('evil_logger')->error('Error connecting to database, will try again', [
                        'exception' => $exception
                    ]);
                } else {
                    throw $exception;
                }
            }
            sleep(10);
        }

        $master = new ConsoleWorkerManager();
        $master->setLogger($this->getContainer()->get('evil_logger'));
        $master->setEnv($this->getContainer()->get('kernel')->getEnvironment());
        $master->setConsole($this->getContainer()->get('kernel')->getRootDir() . '/../bin/console');
        for ($i = 0; $i < $workersRegular; $i++) {
            $master->addWorker("dv:evil:queue-runner {$i}", 1);
        }
        for ($i = 0; $i < $workersPriority; $i++) {
            $master->addWorker("dv:evil:queue-runner --priority {$i}", 1);
        }
        $master->start();
    }
}
