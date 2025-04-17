<?php

namespace ControleOnline\Service;

use React\Socket\ConnectionInterface;
use Doctrine\ORM\EntityManagerInterface;
use SplObjectStorage;
use Symfony\Component\HttpFoundation\RequestStack;

class WebsocketClient
{
    private $clients;

    public function __construct(
        private EntityManagerInterface $manager,
        private RequestStack $requestStack
    ) {
        $this->clients = new SplObjectStorage();
    }

    public function sendMessage(string $type, $message): void
    {
        $payload = json_encode(['type' => $type, 'message' => $message]);
        $this->sendMessageToAll($payload);
    }

    public function addClient(ConnectionInterface $client): void
    {
        $this->clients->attach($client);
    }

    public function removeClient(ConnectionInterface $client): void
    {
        $this->clients->detach($client);
    }

    public function getClients(): \SplObjectStorage
    {
        return $this->clients;
    }

    public function sendMessageToAll(string $payload): void
    {
        $frame = $this->encodeWebSocketFrame($payload);
        foreach ($this->clients as $client) {
            $client->write($frame);
        }
    }

    private function encodeWebSocketFrame(string $payload, int $opcode = 0x1): string
    {
        $frameHead = [];
        $payloadLength = strlen($payload);

        // Opcode (0x1 para texto)
        $frameHead[0] = 0x80 | $opcode;

        // Mascaramento (sempre 0 para o servidor) e comprimento da carga
        if ($payloadLength > 65535) {
            $frameHead[1] = 0x7F;
            $frameHead[2] = ($payloadLength >> 56) & 0xFF;
            $frameHead[3] = ($payloadLength >> 48) & 0xFF;
            $frameHead[4] = ($payloadLength >> 40) & 0xFF;
            $frameHead[5] = ($payloadLength >> 32) & 0xFF;
            $frameHead[6] = ($payloadLength >> 24) & 0xFF;
            $frameHead[7] = ($payloadLength >> 16) & 0xFF;
            $frameHead[8] = ($payloadLength >> 8) & 0xFF;
            $frameHead[9] = $payloadLength & 0xFF;
        } elseif ($payloadLength > 125) {
            $frameHead[1] = 0x7E;
            $frameHead[2] = ($payloadLength >> 8) & 0xFF;
            $frameHead[3] = $payloadLength & 0xFF;
        } else {
            $frameHead[1] = $payloadLength;
        }

        return pack('C*', ...$frameHead) . $payload;
    }

    public function decodeWebSocketFrame(string $data): ?string
    {
        $unmaskedPayload = '';
        $payloadOffset = 2;
        $masked = (ord($data[1]) >> 7) & 0x1;
        $payloadLength = ord($data[1]) & 0x7F;

        if ($payloadLength === 126) {
            $payloadOffset = 4;
            $payloadLength = unpack('n', substr($data, 2, 2))[1];
        } elseif ($payloadLength === 127) {
            $payloadOffset = 10;
            $payloadLength = unpack('J', substr($data, 2, 8))[1];
        }

        if ($masked) {
            if (strlen($data) < $payloadOffset + 4 + $payloadLength) {
                return null; // Frame incompleto
            }
            $maskingKey = substr($data, $payloadOffset, 4);
            $payload = substr($data, $payloadOffset + 4, $payloadLength);
            for ($i = 0; $i < $payloadLength; $i++) {
                $unmaskedPayload .= $payload[$i] ^ $maskingKey[$i % 4];
            }
        } else {
            if (strlen($data) < $payloadOffset + $payloadLength) {
                return null; // Frame incompleto
            }
            $unmaskedPayload = substr($data, $payloadOffset, $payloadLength);
        }

        return $unmaskedPayload;
    }
}
