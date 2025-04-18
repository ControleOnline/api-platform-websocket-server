<?php

namespace ControleOnline\Controller;

use ControleOnline\Service\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class WebSocketController extends AbstractController
{
    public function __construct(private EntityManagerInterface $manager, private WebsocketClient $websocketClient) {}
    #[Route('/websocket', name: "websocket", methods: ["POST"])]
    public function sendMessage(Request $request): JsonResponse
    {
        try {

            $data = json_decode($request->getContent(), true);
            $this->websocketClient->sendMessage($data['type'], $data['message']);

            return new JsonResponse([
                'response' => [
                    'count'   => 1,
                    'error'   => '',
                    'success' => true,
                ],
            ], 200);
        } catch (Throwable $th) {
            return new JsonResponse([
                'response' => [
                    'count'   => 0,
                    'error'   => $th->getMessage(),
                    'file' => $th->getFile(),
                    'line' => $th->getLine(),
                    'success' => false,
                ],
            ], 500);
        }
    }
}
