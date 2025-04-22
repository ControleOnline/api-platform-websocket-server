<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\DeviceService;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Service\HydratorService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class WebSocketController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private DeviceService $deviceService,
        private WebsocketClient $websocketClient,
        private HydratorService $hydratorService,

    ) {}
    #[Route('/websocket', name: "websocket", methods: ["POST"])]
    public function sendMessage(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $device = $this->deviceService->discoveryDevice($data['destination']);
            $integration = $this->websocketClient->push($device, json_encode($data));

            return new JsonResponse($this->hydratorService->item(Integration::class, $integration->getId(), 'integration:write'), Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
