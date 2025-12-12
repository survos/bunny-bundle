<?php

namespace Survos\BunnyBundle\Command;

use Exception;
use Survos\BunnyBundle\Service\BunnyService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use ZipArchive;

#[AsCommand('bunny:download', 'download remote bunny files or directories', help: <<<END
# Download a single file to data/composer.json 
bin/console bunny:download data/composer.json

# Download to a specific local path
bin/console bunny:download remote/path/file.zip ./local/dir/

# Download and unzip
bin/console bunny:download backups/data.zip ./data/ --unzip

# Sync an entire directory
bin/console bunny:download remote/images/ ./local/images/ --sync

# Force re-download even if files exist
bin/console bunny:download data/file.zip --force
END
)]
final class BunnyDownloadCommand
{
    public function __construct(
        private readonly BunnyService $bunnyService,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    /**
     * @throws Exception
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('path within zone (file or directory with --sync)')] string $remoteFilename = '',
        #[Argument('local directory or filename')] ?string $localDirOrFilename = '',
        #[Option('zone name', name: 'zone')] ?string $zoneName = null,
        #[Option('unzip after download')] bool $unzip = false,
        #[Option('sync entire directory recursively')] bool $sync = false,
        #[Option('download even if file exists')] bool $force = false,
    ): int {
        if ($unzip && pathinfo($remoteFilename, PATHINFO_EXTENSION) !== 'zip') {
            $io->error("Only zip files are supported for --unzip");
            return Command::FAILURE;
        }

        if ($sync) {
            return $this->syncDirectory($io, $remoteFilename, $localDirOrFilename, $zoneName, $force);
        }

        return $this->downloadSingleFile($io, $remoteFilename, $localDirOrFilename, $zoneName, $unzip, $force);
    }

    private function downloadSingleFile(
        SymfonyStyle $io,
        string $remoteFilename,
        ?string $localDirOrFilename,
        ?string $zoneName,
        bool $unzip,
        bool $force
    ): int {
        $shortDownloadFilename = pathinfo($remoteFilename, PATHINFO_BASENAME);
        $downloadDir = $this->sanitizeLocalDir($remoteFilename, $localDirOrFilename);

        if (!str_ends_with($downloadDir, '/')) {
            $downloadDir .= '/';
        }

        $filename = (pathinfo($localDirOrFilename, PATHINFO_EXTENSION)) ? basename($localDirOrFilename) : null;

        if (!is_dir($downloadDir)) {
            $io->info("Creating " . $downloadDir);
            mkdir($downloadDir, 0777, true);
        }

        $downloadPath = $filename
            ? $downloadDir . $filename
            : $downloadDir . $shortDownloadFilename;

        $downloadPath = $this->clearDirPath($downloadPath);

        if (!$force && file_exists($downloadPath)) {
            $io->success("File already exists: " . realpath($downloadPath));
            return Command::SUCCESS;
        }

        $remotePath = pathinfo($remoteFilename, PATHINFO_DIRNAME);
        $remoteShortName = pathinfo($remoteFilename, PATHINFO_BASENAME);

        $io->info("Downloading $remoteShortName from $remotePath to $downloadPath");

        // Create progress bar
        $progressBar = null;
        $lastUpdate = 0;

        $this->bunnyService->downloadFileWithProgress(
            filename: $remoteShortName,
            path: $remotePath,
            onProgress: function (int $dlNow, int $dlSize, array $info) use ($io, &$progressBar, &$lastUpdate): void {
                // Initialize progress bar when we know the total size
                if ($progressBar === null && $dlSize > 0) {
                    $progressBar = new ProgressBar($io, $dlSize);
                    $progressBar->setFormat(
                        ' %current_mb%/%max_mb% MB [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %speed%'
                    );
                    $progressBar->setMessage('0', 'current_mb');
                    $progressBar->setMessage($this->formatMB($dlSize), 'max_mb');
                    $progressBar->setMessage('', 'speed');
                    $progressBar->start();
                }

                // Update progress bar (throttle updates to avoid console spam)
                $now = microtime(true);
                if ($progressBar && ($now - $lastUpdate > 0.1 || $dlNow === $dlSize)) {
                    $progressBar->setProgress($dlNow);
                    $progressBar->setMessage($this->formatMB($dlNow), 'current_mb');

                    // Calculate speed
                    $elapsed = $info['total_time'] ?? 0;
                    if ($elapsed > 0) {
                        $speed = $dlNow / $elapsed;
                        $progressBar->setMessage($this->formatSpeed($speed), 'speed');
                    }
                    $lastUpdate = $now;
                }

                // Show indeterminate progress if size unknown
                if ($progressBar === null && $dlNow > 0) {
                    $io->write(sprintf("\r  Downloaded: %s", $this->formatMB($dlNow)));
                }
            },
            storageZone: $zoneName,
            outputPath: $downloadPath
        );

        if ($progressBar) {
            $progressBar->finish();
        }
        $io->newLine(2);

        $size = filesize($downloadPath);
        $io->success(sprintf("Downloaded %s (%s)", realpath($downloadPath), $this->formatMB($size)));

        if ($unzip) {
            $dir = $downloadDir . pathinfo($downloadPath, PATHINFO_FILENAME);
            $io->info("Unzipping to $dir");
            $this->unzip($downloadPath, $dir, $io);

            $table = new Table($io);
            $table->setStyle('compact');
            $table->setHeaders(['name', 'size']);
            foreach (glob($dir . '/*') as $file) {
                $table->addRow([basename($file), $this->formatMB(filesize($file))]);
            }
            $table->render();
        }

        return Command::SUCCESS;
    }

    private function syncDirectory(
        SymfonyStyle $io,
        string $remotePath,
        ?string $localPath,
        ?string $zoneName,
        bool $force
    ): int {
        $localPath = $localPath ?: $this->projectDir . '/' . trim($remotePath, '/');

        if (!is_dir($localPath)) {
            mkdir($localPath, 0777, true);
        }

        $io->title("Syncing directory: $remotePath → $localPath");

        $fileProgressBar = null;
        $currentFile = '';
        $lastUpdate = 0;

        try {
            $downloaded = $this->bunnyService->syncDirectory(
                remotePath: $remotePath,
                localPath: $localPath,
                onFileProgress: function (int $dlNow, int $dlSize, array $info) use ($io, &$fileProgressBar, &$lastUpdate): void {
                    if ($fileProgressBar === null && $dlSize > 0) {
                        $fileProgressBar = new ProgressBar($io, $dlSize);
                        $fileProgressBar->setFormat('    [%bar%] %percent:3s%% %current_mb%/%max_mb% MB');
                        $fileProgressBar->setMessage('0', 'current_mb');
                        $fileProgressBar->setMessage($this->formatMB($dlSize), 'max_mb');
                        $fileProgressBar->start();
                    }

                    $now = microtime(true);
                    if ($fileProgressBar && ($now - $lastUpdate > 0.1 || $dlNow === $dlSize)) {
                        $fileProgressBar->setProgress($dlNow);
                        $fileProgressBar->setMessage($this->formatMB($dlNow), 'current_mb');
                        $lastUpdate = $now;
                    }
                },
                onFileStart: function (string $filename, int $index, int $total) use ($io, &$fileProgressBar, &$currentFile): void {
                    if ($fileProgressBar) {
                        $fileProgressBar->finish();
                        $io->newLine();
                        $fileProgressBar = null;
                    }
                    $currentFile = $filename;
                    $io->writeln("  [$index/$total] $filename");
                },
                storageZone: $zoneName,
                force: $force
            );

            if ($fileProgressBar) {
                $fileProgressBar->finish();
                $io->newLine();
            }

            $io->newLine();
            $io->success(sprintf("Synced %d files to %s", count($downloaded), realpath($localPath)));

            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error("Sync failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function formatMB(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1024 / 1024, 2) . ' MB';
    }

    private function formatSpeed(float $bytesPerSecond): string
    {
        if ($bytesPerSecond < 1024) {
            return round($bytesPerSecond) . ' B/s';
        }
        if ($bytesPerSecond < 1024 * 1024) {
            return round($bytesPerSecond / 1024, 1) . ' KB/s';
        }
        return round($bytesPerSecond / 1024 / 1024, 2) . ' MB/s';
    }

    private function sanitizeLocalDir(string $remoteFilename, string $localDirOrFilename = ""): string
    {
        if ($localDirOrFilename) {
            if (str_ends_with($localDirOrFilename, '/') || !pathinfo($localDirOrFilename, PATHINFO_EXTENSION)) {
                $downloadDir = rtrim($localDirOrFilename, '/');
            } else {
                $downloadDir = pathinfo($localDirOrFilename, PATHINFO_DIRNAME);
                if ($downloadDir === '.') {
                    $downloadDir = '';
                }
            }
        } else {
            $downloadDir = pathinfo($remoteFilename, PATHINFO_DIRNAME);
        }

        if (!str_starts_with($downloadDir, '/')) {
            $downloadDir = $this->projectDir . '/' . $downloadDir;
        }

        return $downloadDir;
    }

    private function unzip(string $zipPath, string $destination, SymfonyStyle $io): void
    {
        $zip = new ZipArchive();
        try {
            if ($zip->open($zipPath) === true) {
                $zip->extractTo($destination);
                $zip->close();
            }
        } catch (Exception $e) {
            $io->error($e->getMessage());
            throw new Exception("Could not unzip $zipPath to $destination");
        }
    }

    private function clearDirPath(string $fullFilePath): string
    {
        $normalizedPath = preg_replace('#/+#', '/', $fullFilePath);

        if ($fullFilePath[0] === '/') {
            $normalizedPath = '/' . ltrim($normalizedPath, '/');
        }

        return $normalizedPath;
    }
}