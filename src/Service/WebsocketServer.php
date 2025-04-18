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
            $deviceId = null;
            $self = self::class;

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer, &$deviceId, $self) {
                $buffer .= $data;
                error_log("Servidor recebeu dados:\n" . $data);

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) {
                        error_log("Servidor: Headers da requisição incompletos.");
                        return;
                    }

                    $lines = explode("\r\n", $buffer);
                    $requestLine = $lines[0];
                    if (preg_match('/GET \/\?device_id=([^ ]+)/', $requestLine, $matches)) {
                        $deviceId = urldecode($matches[1]);
                        error_log("Servidor: Extraído device_id da query string: $deviceId");
                    } else {
                        error_log("Servidor: Nenhum device_id encontrado na query string");
                    }

                    $headers = $self::parseHeaders($buffer);
                    error_log("Servidor: Headers da requisição parsed:\n" . json_encode($headers, JSON_PRETTY_PRINT));
                    $response = $self::generateHandshakeResponse($headers);
                    error_log("Servidor: Resposta de handshake gerada:\n" . $response);

                    if ($response === null) {
                        error_log("Servidor: Resposta de handshake é nula. Fechando a conexão.");
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    error_log("Servidor: Resposta de handshake enviada.");
                    $handshakeDone = true;

                    $clientId = $deviceId ?? uniqid('temp_', true);
                    self::addClient($conn, $clientId);

                    $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);
                    if (!empty($buffer)) {
                        $this->websocketMessage->broadcastMessage($conn, self::$clients, $buffer);
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
                        $this->websocketMessage->broadcastMessage($conn, self::$clients, $frame);
                    }
                }
            });

            $conn->on('close', function () use ($conn, &$deviceId) {
                $clientId = $deviceId ?? array_search($conn, self::$clients, true);
                self::removeClient($conn, $clientId);
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

    private static function addClient(ConnectionInterface $client, string $clientId): void
    {
        self::$clients[$clientId] = $client;
        error_log("Servidor: Cliente conectado (ID: $clientId). Endereço remoto: " . $client->getRemoteAddress());
        error_log("Servidor: Total de clientes: " . count(self::$clients));
        error_log("Servidor: Lista de clientes: " . json_encode(array_keys(self::$clients)));
    }

    private static function removeClient(ConnectionInterface $client, ?string $clientId): void
    {
        if ($clientId && isset(self::$clients[$clientId])) {
            error_log("Servidor: Removendo cliente (ID: $clientId). Endereço remoto: " . $client->getRemoteAddress());
            unset(self::$clients[$clientId]);
            error_log("Servidor: Total de clientes após remoção: " . count(self::$clients));
        } else {
            error_log("Servidor: Cliente não encontrado na lista (Endereço: " . $client->getRemoteAddress() . ")");
        }
    }
}