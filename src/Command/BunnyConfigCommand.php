<?php

namespace Survos\BunnyBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\BunnyBundle\Service\BunnyService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Yaml\Yaml;
use ToshY\BunnyNet\Model\Api\Core\StorageZone\ListStorageZones;
use function Symfony\Component\String\u;

#[AsCommand('bunny:config', 'Configure your application with the bunny keys')]
final class BunnyConfigCommand
{

    public function __construct(
        private readonly BunnyService $bunnyService,
        #[Autowire('%kernel.project_dir%')] private $projectDir,
    )
    {
    }

    public function __invoke(
        SymfonyStyle                                                                                          $io,
        #[Argument('api key')] ?string        $apiKey=null,
        #[Option("Overwrite the survos_bunny.yaml config file")] ?bool $force=null,
        #[Option('limit configuration to just one zone', name: 'zone')] ?string $zoneName = null,

    ): int
    {
        $apiKey = $apiKey??$this->bunnyService->getApiKey();
        if (!$apiKey) {
            $io->error("set environment variable BUNNY_API_KEY or pass it as the first parameter here.");
            return Command::FAILURE;
        }

        // if no zone, we could prompt
        $baseApi = $this->bunnyService->getBaseApi($apiKey);
            $zones = $baseApi->request(new ListStorageZones())->getContents();
            $zoneConfig = [];
            $env[] = "BUNNY_API_KEY=$apiKey";
            foreach ($zones as $zone) {
                if ($zoneName && ($zoneName !== $zone['Name'])) {
                    continue;
                }
                $zoneConstant = u($zone['Name'])->snake()->upper()->toString();
                // inject slugger?  Or try to avoid dependencies?
//                $zoneName = str_replace('-', '_', $zoneName);
                $zoneConfig[] = [
                    'name' => $zone['Name'],
                    'id' => $zone['Id'],
                    'region' => $zone['Region'],
                    'readonly_password' => "%env(BUNNY_{$zoneConstant}_READONLY_PASSWORD)%"
                ];
                $env[] = "BUNNY_{$zoneConstant}_READONLY_PASSWORD=" . $zone['ReadOnlyPassword'];
                $env[] = "BUNNY_{$zoneConstant}_PASSWORD=" . $zone['Password'];
            }
            $config['survos_bunny'] = [
                'api_key' => "%env(BUNNY_API_KEY)%",
                'zones' => $zoneConfig,
            ];

        if ($force) {
            file_put_contents($filename = $this->projectDir . '/config/packages/survos_bunny.yaml', Yaml::dump($config, inline: 4   ));
            $io->success($filename . ' written, add these to your environment, e.g. .env.local or the vault');
        }
        $io->writeln($env);

        return Command::SUCCESS;
    }




}
