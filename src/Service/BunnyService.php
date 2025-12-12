<?php

namespace Survos\BunnyBundle\Service;

use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ToshY\BunnyNet\BunnyHttpClient;
use ToshY\BunnyNet\Enum\Endpoint;
use ToshY\BunnyNet\Model\Api\EdgeStorage\BrowseFiles\ListFiles;
use ToshY\BunnyNet\Model\Api\EdgeStorage\ManageFiles\DownloadFile;
use ToshY\BunnyNet\Model\Api\EdgeStorage\ManageFiles\UploadFile;
use ToshY\BunnyNet\Model\Client\BunnyHttpClientResponseInterface;

class BunnyService
{
    public function __construct(
        private CacheInterface               $cache,
        private readonly HttpClientInterface $httpClient,
        private ?BunnyHttpClient             $bunnyClient = null,
        private ?BunnyHttpClient             $baseApi = null,
        private int                          $cacheTimeout = 0,
        private array                        $config = [],
        private ?BunnyHttpClient             $edgeStorageApi = null,
        private ?string                      $storageZone = null,
        private array                        $zones = []
    ) {
        $timeout = 60 * 60 * 15; // 15 hours
        $this->bunnyClient = new BunnyHttpClient(
            client: new Psr18Client(client: $this->httpClient)->withOptions([
                'timeout' => $timeout
            ]),
            apiKey: $this->getApiKey(),
            baseUrl: Endpoint::BASE
        );
        if (!$this->storageZone) {
            $this->storageZone = $this->config['storage_zone'];
        }
        foreach ($this->config['zones'] as $zoneData) {
            if (!array_key_exists('name', $zoneData)) {
                throw new \LogicException($this->storageZone . " is not defined in config/packages/survos_bunny.yaml");
            }
            $this->zones[$zoneData['name']] = $zoneData;
        }
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getApiKey(): ?string
    {
        return $this->config['api_key'];
    }

    public function getEdgeStorageApi(): ?BunnyHttpClient
    {
        return $this->edgeStorageApi;
    }

    public function getBaseApi(?string $apiKey = null): ?BunnyHttpClient
    {
        if (!$this->baseApi) {
            $apiKey ??= $this->config['api_key'];
            if (!$apiKey) {
                throw new \LogicException('BunnyService requires a base api key');
            }
            $this->baseApi = new BunnyHttpClient(
                client: new Psr18Client($this->httpClient),
                apiKey: $apiKey,
                baseUrl: Endpoint::BASE
            );
        }

        return $this->baseApi;
    }

    public function downloadFile(string $filename, string $path, ?string $storageZone = null): BunnyHttpClientResponseInterface
    {
        $ret = $this->getEdgeApi()->request(
            new DownloadFile(
                storageZoneName: $storageZone ?? $this->getStorageZone(),
                path: $path,
                fileName: $filename
            )
        );

        return $ret;
    }

    /**
     * Download a file with progress callback support for large files.
     * Uses Symfony HttpClient directly to support on_progress callback.
     *
     * @param string $filename The filename to download
     * @param string $path The path within the storage zone
     * @param callable|null $onProgress Callback: function(int $dlNow, int $dlSize, array $info): void
     * @param string|null $storageZone The storage zone name (uses default if null)
     * @param string|null $outputPath If provided, streams directly to file (memory efficient)
     * @return string The file contents (if no outputPath) or the output path
     */
    public function downloadFileWithProgress(
        string    $filename,
        string    $path,
        ?callable $onProgress = null,
        ?string   $storageZone = null,
        ?string   $outputPath = null
    ): string {
        $storageZone = $storageZone ?? $this->getStorageZone();
        $password = $this->zones[$storageZone]['readonly_password'] ?? null;

        if (!$password) {
            throw new \LogicException("please configure zone.$storageZone.readonly_password in survos_bunny.yaml");
        }

        // Build the Edge Storage URL directly
        // Format: https://{region}.storage.bunnycdn.com/{storageZoneName}/{path}/{fileName}
        $baseUrl = Endpoint::EDGE_STORAGE_NY;
        // Ensure we have the scheme
        if (!str_starts_with($baseUrl, 'http')) {
            $baseUrl = 'https://' . $baseUrl;
        }
        $baseUrl = rtrim($baseUrl, '/');
        $cleanPath = trim($path, '/');
        $url = $baseUrl . '/' . $storageZone . '/' . ($cleanPath ? $cleanPath . '/' : '') . $filename;

        $options = [
            'headers' => [
                'AccessKey' => $password,
            ],
        ];

        if ($onProgress) {
            $options['on_progress'] = $onProgress;
        }

        $response = $this->httpClient->request('GET', $url, $options);

        if ($outputPath) {
            // Stream to file - memory efficient for large files
            $fileHandle = fopen($outputPath, 'wb');
            if ($fileHandle === false) {
                throw new \RuntimeException("Cannot open file for writing: $outputPath");
            }

            try {
                foreach ($this->httpClient->stream($response) as $chunk) {
                    fwrite($fileHandle, $chunk->getContent());
                }
            } finally {
                fclose($fileHandle);
            }

            return $outputPath;
        }

        return $response->getContent();
    }

    /**
     * List files in a directory on Bunny storage.
     *
     * @param string $path The path to list
     * @param string|null $storageZone The storage zone name
     * @return array List of files/directories
     */
    public function listFiles(string $path = '', ?string $storageZone = null): array
    {
        $storageZone = $storageZone ?? $this->getStorageZone();

        $ret = $this->getEdgeApi()->request(
            new ListFiles(
                storageZoneName: $storageZone,
                path: $path
            )
        );

        return $ret->getContents();
    }

    /**
     * Sync/download a directory recursively from Bunny storage.
     *
     * @param string $remotePath The remote directory path
     * @param string $localPath The local directory to download to
     * @param callable|null $onFileProgress Progress callback for each file
     * @param callable|null $onFileStart Called when starting a new file: function(string $filename, int $index, int $total): void
     * @param string|null $storageZone The storage zone name
     * @param bool $force Download even if file exists locally
     * @return array List of downloaded files
     */
    public function syncDirectory(
        string    $remotePath,
        string    $localPath,
        ?callable $onFileProgress = null,
        ?callable $onFileStart = null,
        ?string   $storageZone = null,
        bool      $force = false
    ): array {
        $storageZone = $storageZone ?? $this->getStorageZone();
        $files = $this->listFiles($remotePath, $storageZone);
        $downloaded = [];
        $total = count($files);

        if (!is_dir($localPath)) {
            mkdir($localPath, 0777, true);
        }

        foreach ($files as $index => $file) {
            $isDirectory = $file['IsDirectory'] ?? false;
            $fileName = $file['ObjectName'] ?? '';
            $remoteFull = rtrim($remotePath, '/') . '/' . $fileName;
            $localFull = rtrim($localPath, '/') . '/' . $fileName;

            if ($isDirectory) {
                // Recursively sync subdirectories
                $subDownloaded = $this->syncDirectory(
                    $remoteFull,
                    $localFull,
                    $onFileProgress,
                    $onFileStart,
                    $storageZone,
                    $force
                );
                $downloaded = array_merge($downloaded, $subDownloaded);
            } else {
                if (!$force && file_exists($localFull)) {
                    continue;
                }

                if ($onFileStart) {
                    $onFileStart($fileName, $index + 1, $total);
                }

                $this->downloadFileWithProgress(
                    filename: $fileName,
                    path: $remotePath,
                    onProgress: $onFileProgress,
                    storageZone: $storageZone,
                    outputPath: $localFull
                );

                $downloaded[] = $localFull;
            }
        }

        return $downloaded;
    }

    public function uploadFile(
        string $fileName,
        mixed  $body,
        ?string $storageZoneName = null,
        string $path = '',
        array  $headers = [],
    ): BunnyHttpClientResponseInterface {
        $ret = $this->getEdgeApi(writeAccess: true)->request(
            new UploadFile(
                $storageZoneName,
                $path,
                $fileName,
                $body,
                $headers,
            )
        );
        return $ret;
    }

    public function getEdgeApi(string|null $storageZone = null, bool $writeAccess = false): BunnyHttpClient
    {
        if (!$storageZone = $storageZone ?? $this->getStorageZone()) {
            if (!$storageZone = $this->config['storage_zone']) {
                if (count($this->config['zones']) >= 1) {
                    $storageZone = $this->config['zones'][0]['name'];
                }
            }
        }
        assert($storageZone, "Missing storageZone!");

        if (!$this->edgeStorageApi) {
            $password = $this->zones[$storageZone][$passwordType = ($writeAccess ? 'password' : 'readonly_password')] ?? null;
            if (!$password) {
                throw new \LogicException("please configure zone.$storageZone.$passwordType in survos_bunny.yaml");
            }

            $this->edgeStorageApi = new BunnyHttpClient(
                client: new Psr18Client($this->httpClient),
                apiKey: $password,
                baseUrl: Endpoint::EDGE_STORAGE_NY,
            );
        }

        return $this->edgeStorageApi;
    }

    public function getStorageZone(): ?string
    {
        return $this->storageZone ?? $this->config['storage_zone'] ?? null;
    }

    public function getBunnyClient()
    {
        return $this->bunnyClient;
    }

    public function getCacheTimeout(): int
    {
        return $this->cacheTimeout;
    }

    public function setCacheTimeout(int $cacheTimeout): BunnyService
    {
        $this->cacheTimeout = $cacheTimeout;
        return $this;
    }

    public function getCache(): CacheInterface
    {
        return $this->cache;
    }

    public function setCache(CacheInterface $cache): BunnyService
    {
        $this->cache = $cache;
        return $this;
    }

    public function getZones(): array
    {
        return $this->config['zones'];
    }
}