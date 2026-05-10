<?php

/** generated from /home/tac/g/survos/survos/vendor/survos/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\BunnyBundle;

use Survos\BunnyBundle\Command\BunnyConfigCommand;
use Survos\BunnyBundle\Command\BunnyListCommand;
use Survos\BunnyBundle\Command\BunnyDownloadCommand;
use Survos\BunnyBundle\Command\BunnyUploadCommand;
use Survos\BunnyBundle\Controller\BunnyController;
use Survos\BunnyBundle\Service\BunnyService;
use Survos\BunnyBundle\Twig\TwigExtension;
use Survos\SimpleDatatables\SurvosSimpleDatatablesBundle;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class SurvosBunnyBundle extends AbstractBundle
{
    public function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(dirname(__DIR__) . '/src/Controller/', 'attribute');
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // get all bundles https://symfony.com/doc/current/bundles/prepend_extension.html
        $bundles = $builder->getParameter('kernel.bundles');
        $hasSimpleDatatables = in_array(SurvosSimpleDatatablesBundle::class, array_values($bundles));

        $serviceId = 'survos_bunny.bunny_service';
        $container->services()->alias(BunnyService::class, $serviceId);
        $builder->autowire($serviceId, BunnyService::class)
            ->setArgument('$config', $config)
            ->setAutoconfigured(true)
            ->setAutowired(true)
            ->setPublic(true);

        $builder->autowire(BunnyController::class)
            ->setArgument('$simpleDatatablesInstalled', $hasSimpleDatatables)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
        ;

        foreach ([BunnyConfigCommand::class, BunnyListCommand::class, BunnyUploadCommand::class, BunnyDownloadCommand::class] as $commandName) {
            $builder->autowire($commandName)
                ->setAutoconfigured(true)
                ->addTag('console.command')
            ;
        }

        // twig classes, for bunny_url ?
        $builder
            ->autowire('survos.bunny_twig', TwigExtension::class)
            ->setAutoconfigured(true)
            ->addTag('twig.extension');
    }

    private function addZonesSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
            ->arrayNode('zones')
            ->arrayPrototype()
            ->children()
                ->scalarNode('name')->end()
                ->scalarNode('id')->end()
                ->scalarNode('region')->end()
                ->scalarNode('readonly_password')->end()
                ->scalarNode('password')->end()
            ->end()
            ->end()
            ->end();

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $rootNode = $definition->rootNode();
        $rootNode
            ->children()
                ->scalarNode('api_key')->defaultNull()->end()
                ->scalarNode('storage_zone')->defaultValue(null)->end()
//                ->scalarNode('region')->defaultValue(null)->end()
//                ->scalarNode('readonly_password')->defaultValue(null)->end()
//                ->scalarNode('password')->defaultValue(null)->end()
            ->end();

        $this->addZonesSection($rootNode);
    }

}
