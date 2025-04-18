<?php

namespace ControleOnline\Service;

use ControleOnline\Utils\WebSocketUtils;
use React\Socket\ConnectionInterface;

class WebsocketMessage
{
    use WebSocketUtils;

    public function __construct(
        private DeviceService $deviceService
    ) {}

    public function sendMessage(ConnectionInterface $sender, array $clients, string $frame): void
    {
        error_log("Servidor: Recebido frame para broadcast (hex): " . bin2hex($frame));
        $decodedMessage = $this->decodeWebSocketFrame($frame);
        if ($decodedMessage === null) {
            error_log("Servidor: Falha ao decodificar frame WebSocket");
            return;
        }
        error_log("Servidor: Mensagem decodificada (string): $decodedMessage");

        $messageData = json_decode($decodedMessage, true);
        $destination_devices = $clients;

        if (is_array($messageData) && isset($messageData['destination'])) {
            $device = $this->deviceService->discoveryDevice($messageData['destination']);
            if ($device)
                $destination_devices = [$device->getDevice()];
        }

        $encodedFrame = $this->encodeWebSocketFrame($decodedMessage);
        $this->sendMessages($sender,  $destination_devices, $encodedFrame);
    }

    private function sendMessages(ConnectionInterface $sender, array $clients, string $encodedFrame)
    {
        foreach ($clients as $client) {
            if ($client !== $sender) {
                error_log("Servidor: Enviando mensagem para cliente ID: " . $client->resourceId);
                $client->write($encodedFrame);
            }
        }
    }
}
