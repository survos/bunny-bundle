<?php

namespace Survos\BunnyBundle\Command;

use Knp\Bundle\TimeBundle\DateTimeFormatter;
use Survos\BunnyBundle\Service\BunnyService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use ToshY\BunnyNet\Model\Api\Core\StorageZone\ListStorageZones;
use ToshY\BunnyNet\Model\Api\EdgeStorage\BrowseFiles\ListFiles;
use Zenstruck\Bytes;

#[AsCommand('bunny:list', 'list bunny files or zones')]
final class BunnyListCommand
{
    public function __construct(
        private readonly BunnyService $bunnyService,
        private readonly ?DateTimeFormatter $dateTimeFormatter = null
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('path within zone')] string $path = '',
        #[Option('zone name', name: 'zone')] ?string $zoneName = null,
        #[Option('list storage zones instead of files')] bool $zones = false,
    ): int {
        if ($zones) {
            return $this->listZones($io);
        }

        return $this->listFiles($io, $path, $zoneName);
    }

    private function listZones(SymfonyStyle $io): int
    {
        $baseApi = $this->bunnyService->getBaseApi();
        if (!$baseApi) {
            $io->error("Listing zones requires a base api key");
            return Command::FAILURE;
        }

        $zones = $baseApi->request(new ListStorageZones())->getContents();

        $table = new Table($io);
        $table->setHeaderTitle('Storage Zones');
        $table->setHeaders(['Name', 'Storage Used', 'Files', 'Dashboard']);

        foreach ($zones as $zone) {
            $id = $zone['Id'];
            $table->addRow([
                $zone['Name'],
                Bytes::parse($zone['StorageUsed']),
                number_format($zone['FilesStored']),
                "<href=https://dash.bunny.net/storage/$id/file-manager>Open</>",
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function listFiles(SymfonyStyle $io, string $path, ?string $zoneName): int
    {
        $zoneName = $this->resolveZone($io, $zoneName);
        if (!$zoneName) {
            return Command::FAILURE;
        }

        $edgeStorageApi = $this->bunnyService->getEdgeApi($zoneName);
        $list = $edgeStorageApi->request(new ListFiles($zoneName, $path))->getContents();

        if (empty($list)) {
            $io->warning("No files found in $zoneName/$path");
            return Command::SUCCESS;
        }

        $table = new Table($io);
        $table->setHeaderTitle("$zoneName/$path");
        $table->setHeaders(['Name', 'Size', 'Modified', 'Ago']);

        foreach ($list as $file) {
            $isDir = $file['IsDirectory'] ?? false;
            $name = $file['ObjectName'];

            $table->addRow([
                $isDir ? "📁 $name" : $name,
                $isDir ? '-' : Bytes::parse($file['Length']),
                substr($file['DateCreated'], 0, 10),
                $this->dateTimeFormatter?->formatDiff($file['LastChanged']) ?? '-',
            ]);
        }

        $table->render();
        $io->writeln(sprintf(' <info>%d items</info>', count($list)));

        return Command::SUCCESS;
    }

    private function resolveZone(SymfonyStyle $io, ?string $zoneName): ?string
    {
        if ($zoneName) {
            return $zoneName;
        }

        $zoneName = $this->bunnyService->getStorageZone();
        if ($zoneName) {
            return $zoneName;
        }

        $zoneNames = $this->bunnyService->getZoneNames();
        if (empty($zoneNames)) {
            $io->error("No zones configured. Add zones to survos_bunny.yaml");
            return null;
        }

        if (count($zoneNames) === 1) {
            return $zoneNames[0];
        }

        return $io->choice('Select storage zone', $zoneNames);
    }
}