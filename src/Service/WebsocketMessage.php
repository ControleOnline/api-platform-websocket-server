<?php

namespace ControleOnline\Service;

use ControleOnline\Utils\WebSocketUtils;
use React\Socket\ConnectionInterface;

class WebsocketMessage
{
    use WebSocketUtils;
    public function broadcastMessage(ConnectionInterface $sender, $clients, string $frame): void
    {
        error_log("Servidor: Recebido frame para broadcast (hex): " . bin2hex($frame));
        $decodedMessage = $this->decodeWebSocketFrame($frame);
        if ($decodedMessage === null) {
            error_log("Servidor: Falha ao decodificar frame WebSocket");
            return;
        }
        error_log("Servidor: Mensagem decodificada: $decodedMessage");
        $encodedFrame = $this->encodeWebSocketFrame($decodedMessage);
        foreach ($clients as $client) {
            if ($client !== $sender) {
                error_log("Servidor: Enviando mensagem para cliente ID: " . $client->resourceId);
                $client->write($encodedFrame);
            }
        }
    }
}
