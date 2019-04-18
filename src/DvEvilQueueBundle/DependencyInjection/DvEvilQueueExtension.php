<?php
namespace DvEvilQueueBundle\DependencyInjection;

use DvEvilQueueBundle\Command\QueueCommand;
use DvEvilQueueBundle\Command\QueueRunnerCommand;
use DvEvilQueueBundle\Service\EvilService;
use DvEvilQueueBundle\Service\RunnerService;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DvEvilQueueExtension extends Extension
{
    /**
     * @param  array $configs
     * @param  ContainerBuilder $container
     * @return void
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $this->loadConfiguration($configs, $container);
    }

    /**
     * Loads the configuration in, with any defaults
     *
     * @param array $configs
     * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
     * @throws \Exception
     */
    protected function loadConfiguration(array $configs, ContainerBuilder $container)
    {
        $config = $this->processConfiguration(new DvEvilQueueConfiguration(), $configs);
        $container->setParameter('evil_queue.workers', $config['workers']);
        $container->setParameter('evil_queue.priority_workers', $config['priority_workers']);

        $connectionRef = new Reference(str_replace('@', '', $config['connection']));
        $loggerRef = new Reference(str_replace('@', '', $config['logger']));

        $optionDef = new Definition(RunnerService::class);
        $optionDef->addArgument($connectionRef);
        $optionDef->addMethodCall('setLogger', [ $loggerRef ]);
        $optionDef->addMethodCall('setDebug', [ $config['debug'] ]);
        $optionDef->addMethodCall('setRestartAfter', [ $config['restart_after'] ]);
        $optionDef->addMethodCall('setSleepTimes', [ $config['usleep_after_request'], $config['usleep_after_empty'] ]);
        $optionDef->setPublic(true);
        $container->setDefinition('evil_queue', $optionDef);

        $optionDef = new Definition(EvilService::class);
        $optionDef->addArgument($connectionRef);
        $optionDef->setPublic(true);
        $container->setDefinition('evil', $optionDef);

        $optionDef = new Definition(QueueCommand::class);
        $optionDef->addMethodCall('setContainer', [ new Reference('service_container') ]);
        $optionDef->setPublic(true);
        $container->setDefinition('evil.command.queue', $optionDef);
        $optionDef = new Definition(QueueRunnerCommand::class);
        $optionDef->addMethodCall('setContainer', [ new Reference('service_container') ]);
        $optionDef->setPublic(true);
        $container->setDefinition('evil.command.queue_runner', $optionDef);

        $container->setAlias('evil_logger', str_replace('@', '', $config['logger']));
    }
}
