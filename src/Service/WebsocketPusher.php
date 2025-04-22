<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Message\Websocket\PushMessage;
use Symfony\Component\Messenger\MessageBusInterface;

class WebsocketPusher
{
    public function __construct(
        private MessageBusInterface $messageBus
    ) {}

    public function push(Device $device, string $message): void
    {
        $deviceId = $device->getDevice(); 
        $this->messageBus->dispatch(new PushMessage($deviceId, $message));
    }
}