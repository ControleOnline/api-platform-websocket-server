<?php

namespace ControleOnline\Service\Server;

use ControleOnline\Entity\Device;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Utils\WebSocketUtils;
use ControleOnline\Service\Server\WebsocketMessage;
use Exception;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

class WebsocketServer
{
    use WebSocketUtils;

    private static $clients = [];

    public function __construct(
        private WebsocketMessage $websocketMessage,
        private WebsocketClient $websocketClient,
        private IntegrationService $integrationService
    ) {}

    public function init()
    {
        $loop = Loop::get();
        $socket = new SocketServer("0.0.0.0:8080", [], $loop);

        $socket->on('connection', function (ConnectionInterface $conn) {
            $handshakeDone = false;
            $buffer = '';
            $deviceId = null;
            $self = self::class;

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer, &$deviceId, $self) {
                $buffer .= $data;

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) return;
                    $headers = $self::parseHeaders($buffer);
                    if (isset($headers['x-device']))
                        $deviceId = trim($headers['x-device']);

                    $response = $self::generateHandshakeResponse($headers);

                    if ($response === null) {
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;

                    $clientId = $deviceId ?? uniqid('temp_', true);
                    self::addClient($conn, $clientId);

                    $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);
                    if (!empty($buffer))
                        $this->websocketMessage->sendMessage(
                            $conn,
                            self::$clients,
                            $buffer
                        );
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
                        $this->websocketMessage->sendMessage($conn, self::$clients, $frame);
                    }
                }
            });

            $conn->on('close', function () use ($conn, &$deviceId) {
                $clientId = $deviceId ?? array_search($conn, self::$clients, true);
                self::removeClient($conn, $clientId);
            });

            $conn->on('error', function (Exception $e) {
                error_log("Servidor: Erro na conexão: " . $e->getMessage());
            });
        });

        $socket->on('error', function (Exception $e) {
            error_log("Servidor: Erro no socket principal: " . $e->getMessage());
        });

        $this->consumeMessages($loop);
        $loop->run();
    }

    private function consumeMessages($loop): void
    {
        $loop->addPeriodicTimer(1, function () {
            try {


                $integrations = $this->integrationService->getOpenMessages('Websocket');

                foreach ($integrations as $integration)
                    $this->sendToClient($integration->getDevice(), $integration->getBody());
            } catch (Exception $e) {
                error_log("Servidor: Erro ao consumir mensagem: " . $e->getMessage());
            }
        });
    }

    private function sendToClient(Device $device, string $message): void
    {
        if (isset(self::$clients[$device->getDevice()])) {
            $client = self::$clients[$device->getDevice()];
            try {
                $frame = self::encodeWebSocketFrame($message, 0x1);
                $client->write($frame);
            } catch (Exception $e) {
                error_log("Servidor: Erro ao enviar mensagem para o dispositivo {$device->getDevice()}: " . $e->getMessage());
                self::removeClient($client, $device->getDevice());
            }
        } else {
            error_log("Servidor: Dispositivo {$device->getDevice()} não está conectado.");
        }
    }

    private static function addClient(ConnectionInterface $client, string $clientId): void
    {
        self::$clients[$clientId] = $client;
    }

    private static function removeClient(ConnectionInterface $client, ?string $clientId): void
    {
        if ($clientId && isset(self::$clients[$clientId])) {
            unset(self::$clients[$clientId]);
        } else {
            error_log("Servidor: Cliente não encontrado na lista (Endereço: " . $client->getRemoteAddress() . ")");
        }
    }
}
