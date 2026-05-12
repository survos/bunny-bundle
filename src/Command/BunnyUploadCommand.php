<?php

namespace Survos\BunnyBundle\Command;

use Exception;
use Survos\BunnyBundle\Service\BunnyService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function PHPUnit\Framework\throwException;
use function Symfony\Component\String\u;

#[AsCommand('bunny:upload', 'upload remote bunny files', help: <<<END
# if local path is within project, mirror the file structure, otherwise pass it as second argument
bin/console bunny:upload local/path/filename.zip [remote/path/filename.zip] --zone=zoneName 
# change name and path
bin/console bunny:upload local/path/filename.zip remote/path/newFilename.zip 
# change remote path only, needs slash at the end!
bin/console bunny:upload local/path/filename.zip remote/path/

remote path is required unless a mirror of local
END)]
final class BunnyUploadCommand
{

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private readonly BunnyService                      $bunnyService,
    )
    {
    }

    /**
     * @throws Exception
     */
    public function __invoke(
        SymfonyStyle                                                        $io,
        #[Argument(description: 'file name to upload')] string    $filename = '',
        #[Argument(description: 'path within zone')] string       $remoteDirOrFilename = '',
        #[Option(name: 'zone', description: 'zone name')] ?string $zoneName = null,
        #[Option(name: 'zip', description: 'isZip?')] ?bool       $zip = null,
//        #[Option(description: 'dir')] ?string $relativeDir = './',
    ): int
    {
        if (!file_exists($filename)) {
            $io->error("File $filename does not exist");
            return Command::FAILURE;
        }

        if (!$zip && is_dir($filename)) {
            $io->error("Please specify --zip for directories");
            return Command::FAILURE;
        }

        if ($zip) {
            $filename = $this->createZip($filename);
        }

        $remoteFilename = pathinfo($filename, PATHINFO_BASENAME);
        $remotePath = $remoteDirOrFilename;

        if (!$remoteDirOrFilename) {
            // if the real path contains the project dir, this is a candidate for sync
            $realPath = realpath($filename);
            if (str_starts_with($realPath, $this->projectDir)) {
                $remotePath = pathinfo(
                    str_replace($this->projectDir . '/', '', $realPath), PATHINFO_DIRNAME);
                if ($remotePath === '.') {
                    $remotePath = '';
                }
            }
        } else {
            // keep the filename but change the dir
            if (str_ends_with($remoteDirOrFilename, '/')) {
                $remotePath = $remoteDirOrFilename;
            }
        }

        if (!$zoneName) {
            $zoneName = $this->bunnyService->getStorageZone();
        }

        /*
 * File contents handle from a `$filesystem` (e.g. Flysystem FtpAdapter).
 */
        if ($stream = fopen($filename, 'r')) {

            // print all the page starting at the offset 10

            $content = stream_get_contents($stream);

            // remotePath should have the slash
            if (!u($remotePath)->endsWith('/')) {
                $remotePath .= "/";
            }
            $io->info("Uploading $filename to $zoneName/$remotePath$remoteFilename");

            $ret = $this->bunnyService->uploadFile(
                $remoteFilename,
                storageZoneName: $zoneName,
                body: $content,
                path: $remotePath . '/'
            );
            fclose($stream);
            $io->info($ret->getStatusCode() . ' ' . $ret->getReasonPhrase());
        }


        $io->success($filename . " has been uploaded to $zoneName/$remotePath$filename");

        // @todo: download dir default, etc.

        $io->success(self::class . ' finished');
        return Command::SUCCESS;
    }

    /**
     * @param string $filePath
     * @return string
     */
    private function adjustFilePath(string $filePath): string
    {
        // Check if the file path starts with "/"
        if (!str_starts_with($filePath, '/')) {
            // If not, add the current directory path to it
            $filePath = $this->projectDir . '/' . $filePath;
        }

        return $filePath;
    }

    /**
     * @param string $filename
     * @return string
     * @throws Exception
     */
    private function createZip(string $filename): string
    {
        if (is_dir($filename)) {
            return $this->createZipFolder($filename);
        }

        if (is_file($filename)) {
            return $this->createZipFile($filename);
        }

        throw new Exception("Not a file or folder!");
    }

    /**
     * @param string $filename
     * @return string
     * @throws Exception
     */
    private function createZipFile(string $filename): string
    {
        $zip = new \ZipArchive();

        $fullFilePath = $this->adjustFilePath($filename);
        $zipFileName = $this->getZipPath($fullFilePath);

        if ($zip->open($zipFileName, \ZipArchive::CREATE) === TRUE) {
            $zip->addFile($fullFilePath, basename($fullFilePath)); // Add file to zip
            $zip->close();
            return $zipFileName;
        }

        throw new Exception("Failed to create zip file!");
    }

    /**
     * @param string $folder
     * @return string
     * @throws Exception
     */
    private function createZipFolder(string $folder): string
    {
        $fullFolderPath = $this->adjustFilePath($folder);

        $updatedPath = rtrim($fullFolderPath, '/');
        $zipFileName = explode('/', $updatedPath);

        $zipFileName = $this->getZipPath($updatedPath . '/' . end($zipFileName));

        $zip = new \ZipArchive();

        if ($zip->open($zipFileName, \ZipArchive::CREATE) === TRUE) {
            // Add folder and its contents recursively
            $folderPath = realpath($fullFolderPath);

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($folderPath),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    // Get the real and relative path for the current file
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($folderPath) + 1);

                    // Add file to zip archive
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();
            return $zipFileName;
        }

        throw new Exception("Failed to create zip file!");
    }

    /**
     * @param string $filePath
     * @return string
     */
    private function getZipPath(string $filePath): string
    {
        if (!str_ends_with($filePath, '.zip')) {
            $filePath .= '.zip';
        }
        return $filePath;
    }
}
