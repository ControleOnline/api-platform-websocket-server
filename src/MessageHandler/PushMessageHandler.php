<?php

namespace ControleOnline\MessageHandler\Websocket;

use ControleOnline\Entity\Device;
use ControleOnline\Message\Websocket\PushMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use ControleOnline\Service\WebsocketPusher;
use Doctrine\ORM\EntityManagerInterface;

#[AsMessageHandler]
class PushMessageHandler
{
    public function __construct(
        private WebsocketPusher $pusher,
        private  EntityManagerInterface $manager
    ) {}

    public function __invoke(PushMessage $message)
    {
        $device = $this->manager->getRepository(Device::class)->find($message->deviceId);
        $this->pusher->push($device, $message->message);
    }
}
