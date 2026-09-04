<?php

namespace ControleOnline\Controller;

use ControleOnline\Service\RequestPayloadService;
use ControleOnline\Service\WebsocketTestEventService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class WebSocketTestEventController extends AbstractController
{
    public function __construct(
        private RequestPayloadService $requestPayloadService,
        private WebsocketTestEventService $websocketTestEventService,
    ) {
    }

    #[Route('/websocket/test-event', name: 'websocket_test_event', methods: ['POST'])]
    public function sendTestEvent(Request $request): JsonResponse
    {
        try {
            $data = $this->requestPayloadService->decodeJsonContent($request->getContent());
            $result = $this->websocketTestEventService->dispatch($data);

            return new JsonResponse([
                'errno' => 0,
                'errmsg' => 'ok',
                'data' => $result,
            ], Response::HTTP_OK);
        } catch (Throwable $exception) {
            return new JsonResponse([
                'errno' => 1,
                'errmsg' => $exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
