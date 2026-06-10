<?php

namespace Survos\BunnyBundle\Controller;

use Survos\BunnyBundle\Service\BunnyService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use ToshY\BunnyNet\Model\Api\Core\StorageZone\ListStorageZones;
use ToshY\BunnyNet\Model\Api\EdgeStorage\BrowseFiles\ListFiles;

class BunnyController extends AbstractController
{
    public function __construct(
        private BunnyService $bunnyService,
        private $simpleDatatablesInstalled = false
    )
    {

    }

    private function checkSimpleDatatablesInstalled()
    {
        if (! $this->simpleDatatablesInstalled) {
            throw new \LogicException("This page requires SimpleDatatables\n composer req survos/simple-datatables-bundle");
        }
    }
    #[Route('/zones', name: 'survos_bunny_zones', methods: ['GET'])]
    #[Template('@SurvosBunny/zones.html.twig')]
    public function zones(
    ): Response|array
    {
        $this->checkSimpleDatatablesInstalled();
        $baseApi = $this->bunnyService->getBaseApi();
        return ['zones' => $baseApi->request(new ListStorageZones())->getContents()];
    }

    #[Route('/{zoneName}}/{path}/{fileName}', name: 'survos_bunny_download', methods: ['GET'], requirements: ['path'=> ".+"])]
    #[Template('@SurvosBunny/zone.html.twig')]
    public function download(string $zoneName, string $path, string $fileName): Response
    {

        $response = $this->bunnyService->downloadFile($fileName,$path,$zoneName);
        return new Response($response->getContents()); // eh
    }


    #[Route('/{zoneName}/{id}/{path}', name: 'survos_bunny_zone', methods: ['GET'])]
    #[Template('@SurvosBunny/zone.html.twig')]
    public function zone(
        string $zoneName,
        string $id,
        ?string $path='/'
    ): Response|array
    {
        $this->checkSimpleDatatablesInstalled();
        $edgeStorageApi = $this->bunnyService->getEdgeApi($zoneName);
        $list = $edgeStorageApi->request(new ListFiles($zoneName, $path));
        return [
            'zoneName' => $zoneName,
            'path' => $path,
            'files' => $list->getContents()
        ];
    }
}
