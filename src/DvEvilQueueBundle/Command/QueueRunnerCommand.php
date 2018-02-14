<?php
namespace DvEvilQueueBundle\Command;

use Druidvav\EssentialsBundle\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueRunnerCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('dv:evil:queue-runner')
            ->addArgument('worker')
            ->addOption('priority', 'p', InputOption::VALUE_NONE, 'Only priority jobs');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cWorker = $input->getArgument('worker');
        $isPriority = $input->getOption('priority') === true;
        $evilQueue = $this->getContainer()->get('evil_queue');
        $evilQueue->setWorker($cWorker, $isPriority);
        $evilQueue->run();
    }
}
