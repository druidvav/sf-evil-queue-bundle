<?php
namespace DvEvilQueueBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class DvEvilQueueExtension extends Extension
{
    /**
     * @param  array            $configs
     * @param  ContainerBuilder $container
     * @return void
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

        $optionDef = new Definition('DvEvilQueueBundle\Service\RunnerService');
        $optionDef->addArgument($connectionRef);
        $optionDef->addMethodCall('setLogger', [ $loggerRef ]);
        $optionDef->addMethodCall('setDebug', [ $config['debug'] ]);
        $optionDef->setPublic(true);
        $container->setDefinition('evil_queue', $optionDef);

        $optionDef = new Definition('DvEvilQueueBundle\Service\EvilService');
        $optionDef->addArgument($connectionRef);
        $optionDef->setPublic(true);
        $container->setDefinition('evil', $optionDef);

        $container->setAlias('evil_logger', str_replace('@', '', $config['logger']));
    }
}
