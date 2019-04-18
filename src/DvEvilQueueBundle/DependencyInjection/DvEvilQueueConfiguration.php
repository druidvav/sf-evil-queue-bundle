<?php
namespace DvEvilQueueBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class DvEvilQueueConfiguration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree builder.
     *
     * @return \Symfony\Component\Config\Definition\Builder\TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root("dv_evil_queue");

        $rootNode->
            children()->
                scalarNode("connection")->defaultValue('@doctrine.dbal.default_connection')->end()->
                scalarNode("logger")->defaultValue('@logger')->end()->
                scalarNode("workers")->defaultValue(1)->end()->
                scalarNode("priority_workers")->defaultValue(1)->end()->
                scalarNode("debug")->defaultFalse()->end()->
                scalarNode("usleep_after_request")->defaultValue(50000)->end()->
                scalarNode("usleep_after_empty")->defaultValue(2000000)->end()->
            end()
        ;

        return $treeBuilder;
    }
}
