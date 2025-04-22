<?php

namespace ControleOnline\Service\Server;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\Integration;
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
        error_log("Servidor: Iniciando servidor no processo " . getmypid());
        $loop = Loop::get();
        $socket = new SocketServer("0.0.0.0:8080", [], $loop);

        $socket->on('connection', function (ConnectionInterface $conn) {
            $handshakeDone = false;
            $buffer = '';
            $deviceId = null;
            $self = self::class;

            error_log("Servidor: Nova conexão recebida de " . $conn->getRemoteAddress());

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer, &$deviceId, $self) {
                $buffer .= $data;

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) return;
                    $headers = $self::parseHeaders($buffer);
                    error_log("Servidor: Cabeçalhos recebidos: " . json_encode($headers));
                    if (!isset($headers['x-device']) || empty(trim($headers['x-device']))) {
                        error_log("Servidor: Cabeçalho x-device ausente ou inválido");
                        $conn->close();
                        return;
                    }
                    $deviceId = trim($headers['x-device']);
                    error_log("Servidor: Device ID: $deviceId");

                    $response = $self::generateHandshakeResponse($headers);
                    if ($response === null) {
                        error_log("Servidor: Handshake falhou");
                        $conn->close();
                        return;
                    }

                    // Verificar conexão duplicada antes de aceitar
                    if (isset(self::$clients[$deviceId])) {
                        error_log("Servidor: Conexão duplicada para $deviceId. Rejeitando nova conexão.");
                        $conn->write("HTTP/1.1 409 Conflict\r\n\r\nDevice already connected");
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;

                    error_log("Servidor: Registrando cliente com ID: $deviceId");
                    self::addClient($conn, $deviceId);

                    $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);
                    if (!empty($buffer))
                        $this->websocketMessage->sendMessage($conn, self::$clients, $buffer);
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
                error_log("Servidor: Conexão fechada, Client ID: " . ($clientId ?: 'Desconhecido') . ", Endereço: " . $conn->getRemoteAddress() . ", Processo: " . getmypid());
                self::removeClient($conn, $clientId);
            });

            $conn->on('error', function (Exception $e) {
                error_log("Servidor: Erro na conexão: " . $e->getMessage() . " | Stack: " . $e->getTraceAsString());
            });
        });

        $socket->on('error', function (Exception $e) {
            error_log("Servidor: Erro no socket principal: " . $e->getMessage());
        });

        // Adicionar ping para detectar conexões inativas
        $loop->addPeriodicTimer(30, function () {
            foreach (self::$clients as $clientId => $client) {
                try {
                    $client->write(self::encodeWebSocketFrame('', 0x9)); // Ping frame
                    error_log("Servidor: Enviando ping para $clientId");
                } catch (Exception $e) {
                    error_log("Servidor: Erro ao enviar ping para $clientId: " . $e->getMessage());
                    self::removeClient($client, $clientId);
                }
            }
        });

        $this->consumeMessages($loop);
        $loop->run();
    }

    private function consumeMessages($loop): void
    {
        $loop->addPeriodicTimer(1, function () {
            try {
                $integrations = $this->integrationService->getOpenMessages('Websocket');
                error_log("Servidor: Mensagens recebidas: " . count($integrations) . ", Processo: " . getmypid());
                foreach ($integrations as $integration) {
                    $this->sendToClient($integration);
                }
            } catch (Exception $e) {
                error_log("Servidor: Erro ao consumir mensagem: " . $e->getMessage());
            }
        });
    }

    private function sendToClient(Integration $integration): void
    {
        $device = $integration->getDevice();
        $message = $integration->getBody();
        $deviceId = $device->getDevice();
        error_log("Servidor: Tentando enviar para dispositivo: $deviceId no processo " . getmypid());

        if (isset(self::$clients[$deviceId])) {
            $client = self::$clients[$deviceId];
            try {
                $frame = self::encodeWebSocketFrame($message, 0x1);
                $client->write($frame);
                error_log("Servidor: Frame enviado para $deviceId: " . bin2hex($frame));
                $this->integrationService->setDelivered($integration);
            } catch (Exception $e) {
                error_log("Servidor: Erro ao enviar mensagem para $deviceId: " . $e->getMessage());
                self::removeClient($client, $deviceId);
            }
        } else {
            error_log("Servidor: Dispositivos conectados: " . json_encode(array_keys(self::$clients)));
            error_log("Servidor: Dispositivos conectados: " . count(self::$clients));
            error_log("Servidor: Dispositivo $deviceId não está conectado.");
        }
    }

    private static function addClient(ConnectionInterface $client, string $clientId): void
    {
        self::$clients[$clientId] = $client;
        error_log("Servidor: Cliente adicionado: $clientId, Total: " . count(self::$clients) . ", Clientes: " . json_encode(array_keys(self::$clients)) . ", Processo: " . getmypid());
    }

    private static function removeClient(ConnectionInterface $client, ?string $clientId): void
    {
        if ($clientId && isset(self::$clients[$clientId])) {
            unset(self::$clients[$clientId]);
            error_log("Servidor: Cliente removido: $clientId, Total: " . count(self::$clients) . ", Clientes: " . json_encode(array_keys(self::$clients)) . ", Processo: " . getmypid());
        } else {
            error_log("Servidor: Cliente não encontrado na lista (Endereço: " . $client->getRemoteAddress() . "), Processo: " . getmypid());
        }
    }
}
