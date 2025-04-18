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
                error_log("Servidor recebeu dados:\n" . $data);

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) {
                        error_log("Servidor: Headers da requisição incompletos.");
                        return;
                    }

                    $headers = $this->parseHeaders($buffer);
                    error_log("Servidor: Headers da requisição parsed:\n" . json_encode($headers, JSON_PRETTY_PRINT));
                    $response = $this->generateHandshakeResponse($headers);
                    error_log("Servidor: Resposta de handshake gerada:\n" . $response);

                    if ($response === null) {
                        error_log("Servidor: Resposta de handshake é nula. Fechando a conexão.");
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    error_log("Servidor: Resposta de handshake enviada.");
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
                error_log("Servidor: Conexão fechada.");
            });

            $conn->on('error', function (Exception $e) {
                error_log("Servidor: Erro na conexão: " . $e->getMessage());
            });
        });

        $socket->on('error', function (Exception $e) {
            error_log("Servidor: Erro no socket principal: " . $e->getMessage());
        });

        Loop::get()->run();
    }


    private static function addClient(ConnectionInterface $client): void
    {
        self::$clients[$client->resourceId] = $client;
        error_log("Servidor: Cliente conectado (ID: " . $client->resourceId . "). Total de clientes: " . count(self::$clients));
    }

    private static function removeClient(ConnectionInterface $client): void
    {
        unset(self::$clients[$client->resourceId]);
        error_log("Servidor: Cliente desconectado (ID: " . $client->resourceId . "). Total de clientes: " . count(self::$clients));
    }

    private static function getClients(): array
    {
        return self::$clients;
    }
}
