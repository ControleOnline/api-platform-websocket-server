<?php

namespace ControleOnline\Service\Server;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\LoggerService;
use ControleOnline\Utils\WebSocketUtils;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

class WebsocketServer
{
    use WebSocketUtils;

    private static array $clients = [];
    private static array $pending = [];

    public function __construct(
        private WebsocketMessage $websocketMessage,
        private WebsocketClient $websocketClient,
        private IntegrationService $integrationService,
        private LoggerService $loggerService
    ) {
        self::$logger = $loggerService->getLogger('websocket');
    }

    public function init(string $bind, string $port): void
    {
        self::$logger->info("Servidor WebSocket iniciado no processo " . getmypid() . " em {$bind}:{$port}");
        $loop = Loop::get();
        $socket = new SocketServer($bind . ':' . $port, [], $loop);

        $socket->on('connection', function (ConnectionInterface $conn) {
            $handshakeDone = false;
            $buffer = '';
            $connId = spl_object_id($conn);

            self::$logger->info("Nova conexão de " . $conn->getRemoteAddress() . " (connId: {$connId})");

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer, $connId) {
                $buffer .= $data;

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) return;

                    $headers = self::parseHeaders($buffer);
                    $response = self::generateHandshakeResponse($headers);
                    if (!$response) {
                        self::$logger->error("Handshake falhou para connId {$connId}");
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;
                    self::$pending[$connId] = $conn;
                    $pos = strpos($buffer, "\r\n\r\n") + 4;
                    $remaining = substr($buffer, $pos);
                    $buffer = '';
                    if ($remaining) $this->processData($conn, $remaining);
                } else {
                    $this->processData($conn, $buffer);
                    $buffer = '';
                }
            });

            $conn->on('close', fn() => $this->cleanupConnection($conn));
            $conn->on('error', fn($e) => $this->cleanupConnection($conn));
        });

        // Ping para manter conexões vivas
        $loop->addPeriodicTimer(20, function () {
            foreach (self::$clients as $deviceId => $client) {
                try {
                    $client->write(self::encodeWebSocketFrame('', 0x9));
                } catch (\Exception $e) {
                    $this->removeClient($client, $deviceId);
                }
            }
        });

        $this->consumeMessages($loop);
        $loop->run();
    }

    private function processData(ConnectionInterface $conn, string $data): void
    {
        $connId = spl_object_id($conn);

        if (isset(self::$pending[$connId])) {
            $decoded = self::decodeWebSocketFrame($data);
            if (!$decoded || $decoded['opcode'] !== 0x1) {
                $conn->close();
                return;
            }

            $payload = json_decode($decoded['payload'], true);
            if (!isset($payload['command']) || $payload['command'] !== 'identify') {
                $conn->close();
                return;
            }

            $deviceId = trim($payload['device'] ?? '');
            if (!$deviceId || isset(self::$clients[$deviceId])) {
                $conn->write(self::encodeWebSocketFrame(json_encode([
                    'status' => 'error',
                    'message' => $deviceId ? 'Device already connected' : 'Device ID vazio'
                ]), 0x1));
                $conn->close();
                return;
            }

            self::$clients[$deviceId] = $conn;
            unset(self::$pending[$connId]);

            $conn->write(self::encodeWebSocketFrame(json_encode([
                'status' => 'identified',
                'device' => $deviceId
            ]), 0x1));
            return;
        }

        // Cliente já identificado
        $this->websocketMessage->sendMessage($conn, self::$clients, $data);
    }

    private function cleanupConnection(ConnectionInterface $conn): void
    {
        $connId = spl_object_id($conn);
        unset(self::$pending[$connId]);

        $deviceId = array_search($conn, self::$clients, true);
        if ($deviceId !== false) unset(self::$clients[$deviceId]);
    }

    private function removeClient(ConnectionInterface $client, ?string $deviceId): void
    {
        if ($deviceId && isset(self::$clients[$deviceId])) {
            unset(self::$clients[$deviceId]);
        }
    }

    private function consumeMessages($loop): void
    {
        $loop->addPeriodicTimer(1, function () {
            if (!self::$clients) return;
            $devices = array_keys(self::$clients);
            $integrations = $this->integrationService->getWebsocketOpen($devices);
            foreach ($integrations as $integration) {
                $this->sendToClient($integration);
            }
        });
    }

    private function sendToClient(Integration $integration): void
    {
        $deviceId = $integration->getDevice()->getDevice();
        $message = $integration->getBody();

        if (isset(self::$clients[$deviceId])) {
            $client = self::$clients[$deviceId];
            try {
                $frame = self::encodeWebSocketFrame($message, 0x1);
                $client->write($frame);
                $this->integrationService->setDelivered($integration);
            } catch (\Exception $e) {
                self::$logger->error("Erro ao enviar mensagem para {$deviceId}");
                $this->removeClient($client, $deviceId);
                $this->integrationService->setError($integration);
            }
        }
    }
}
