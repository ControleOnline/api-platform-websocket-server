<?php

namespace ControleOnline\Service;

use ControleOnline\Utils\WebSocketUtils;
use React\Socket\ConnectionInterface;

class WebsocketMessage
{
    use WebSocketUtils;
    public function broadcastMessage(ConnectionInterface $sender, $clients, string $frame): void
    {
        $decodedMessage = $this->decodeWebSocketFrame($frame);
        if ($decodedMessage !== null) {
            $encodedFrame = $this->encodeWebSocketFrame($decodedMessage);
            foreach ($clients as $client) {
                if ($client !== $sender) {
                    $client->write($encodedFrame);
                }
            }
        }
    }
}
