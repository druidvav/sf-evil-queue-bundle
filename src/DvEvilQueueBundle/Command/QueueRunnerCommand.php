<?php
namespace DvEvilQueueBundle\Command;

use Druidvav\EssentialsBundle\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueRunnerCommand extends Command
{
    protected $running = true;

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
        declare(ticks = 1);

        $runtimeStart = time();
        $log = $this->getContainer()->get('evil_logger');
        $cWorker = $input->getArgument('worker');
        $isPriority = $input->getOption('priority') === true;
        $evilQueue = $this->getContainer()->get('evil_queue');
        $evilQueue->setWorker($cWorker, $isPriority);

        pcntl_signal(SIGINT, function () use ($log) {
            $log->info('Got SIGINT, stopping...');
            $this->running = false;
        });

        pcntl_signal(SIGTERM, function () use ($log) {
            $log->info('Got SIGTERM, stopping...');
            $this->running = false;
        });

        $tmpDir = sys_get_temp_dir();
        if (!is_writable($tmpDir)) {
            $log->error('Temporary directory is not writable');
            exit;
        }

        $log->info('Worker started: #' . $cWorker);
        while ($this->running) {
            $evilQueue->tick();
            if ($evilQueue->getDebug()) {
                $memoryUsage      = memory_get_usage();
                $runtime          = time() - $runtimeStart;
                $memoryPeakUsage  = memory_get_peak_usage();
                $stat = "Runtime: {$runtime}sec; Memory Usage: {$memoryUsage}b, peak: {$memoryPeakUsage}b";
                file_put_contents($tmpDir . '/evil_thread_' . $cWorker, $stat);
                $log->debug($stat);
            }
            pcntl_signal_dispatch();
        }
        $log->info('Worker stopped: #' . $cWorker);
    }
}
