<?php

namespace ControleOnline\Service;

use ControleOnline\Utils\WebSocketUtils;
use Exception;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

class WebsocketServer
{
    use WebSocketUtils;

    private static $clients = [];

    public function __construct(
        private WebsocketMessage $websocketMessage
    ) {}


    public function init()
    {
        $socket = new SocketServer("0.0.0.0:8080", [], Loop::get());

        $socket->on('connection', function (ConnectionInterface $conn) {
            $handshakeDone = false;
            $buffer = '';

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer) {
                $buffer .= $data;

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) {
                        return;
                    }

                    $headers = $this->parseHeaders($buffer);
                    $response = $this->generateHandshakeResponse($headers);

                    if ($response === null) {
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;
                    self::addClient($conn);

                    $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);
                    if (!empty($buffer)) {
                        $this->websocketMessage->broadcastMessage($conn, self::getClients(), $buffer);
                    }
                } else {
                    while (strlen($buffer) >= 2) {
                        $masked = (ord($buffer[1]) >> 7) & 0x1;
                        $payloadLength = ord($buffer[1]) & 0x7F;
                        $payloadOffset = 2;

                        if ($payloadLength === 126) {
                            $payloadOffset = 4;
                            $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
                        } elseif ($payloadLength === 127) {
                            $payloadOffset = 10;
                            $payloadLength = unpack('J', substr($buffer, 2, 8))[1];
                        }

                        $frameLength = $payloadOffset + ($masked ? 4 : 0) + $payloadLength;
                        if (strlen($buffer) < $frameLength) {
                            break;
                        }

                        $frame = substr($buffer, 0, $frameLength);
                        $buffer = substr($buffer, $frameLength);
                        $this->websocketMessage->broadcastMessage($conn, self::getClients(), $frame);
                    }
                }
            });

            $conn->on('close', function () use ($conn) {
                self::removeClient($conn);
            });
        });

        $socket->on('error', function (Exception $e) {});
        Loop::get()->run();
    }


    private static function addClient(ConnectionInterface $client): void
    {
        self::$clients[$client->resourceId] = $client;
    }

    private static function removeClient(ConnectionInterface $client): void
    {
        unset(self::$clients[$client->resourceId]);
    }

    private static function getClients(): array
    {
        return self::$clients;
    }
}
