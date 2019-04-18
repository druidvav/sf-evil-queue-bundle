<?php
namespace DvEvilQueueBundle\Command;

use DvEvilQueueBundle\Service\RunnerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueRunnerCommand extends Command
{
    /** @var RunnerService */
    protected $runner;

    public function setRunner(RunnerService $runner)
    {
        $this->runner = $runner;
    }

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
        $this->runner->setWorker($cWorker, $isPriority);
        $this->runner->run();
    }
}
