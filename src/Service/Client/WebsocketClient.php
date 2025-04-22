<?php

namespace ControleOnline\Service\Client;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\Integration;
use ControleOnline\Service\Asaas\IntegrationService;
use Doctrine\ORM\EntityManagerInterface;

class WebsocketClient
{
    public function __construct(
        private EntityManagerInterface $manager,
        private IntegrationService $integrationService
    ) {}

    public function push(Device $device, string $message): Integration
    {
        return $this->integrationService->addIntegration($message, 'Websocket', $device);
    }
}
