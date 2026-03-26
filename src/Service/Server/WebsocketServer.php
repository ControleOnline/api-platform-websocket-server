<?php

namespace ControleOnline\Service\Server;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\LoggerService;
use ControleOnline\Utils\WebSocketUtils;
use ControleOnline\Service\Server\WebsocketMessage;
use Exception;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

class WebsocketServer
{
    use WebSocketUtils;

    private static array $clients = [];   // deviceId => ConnectionInterface
    private static array $pending = [];   // connId => ConnectionInterface

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

            self::$logger->info("Nova conexão recebida de " . $conn->getRemoteAddress() . " (connId: {$connId})");

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer, $connId) {
                $buffer .= $data;

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) {
                        return;
                    }

                    $headers = self::parseHeaders($buffer);
                    self::$logger->info("Handshake recebido. Headers: " . json_encode($headers));

                    $response = self::generateHandshakeResponse($headers);
                    if ($response === null) {
                        self::$logger->error("Handshake falhou para connId: {$connId}");
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;

                    $pos = strpos($buffer, "\r\n\r\n") + 4;
                    $remaining = substr($buffer, $pos);
                    $buffer = '';

                    self::$pending[$connId] = $conn;
                    self::$logger->info("Handshake OK. Aguardando 'identify' (connId: {$connId})");

                    if (strlen($remaining) > 0) {
                        self::$logger->info("Dados residuais após handshake: " . strlen($remaining) . " bytes");
                        $this->processData($conn, $remaining);
                    }
                } else {
                    $this->processData($conn, $buffer);
                    $buffer = '';
                }
            });

            $conn->on('close', function () use ($conn, $connId) {
                self::$logger->info("Conexão fechada (connId: {$connId})");
                $this->cleanupConnection($conn);
            });

            $conn->on('error', function (Exception $e) use ($conn, $connId) {
                self::$logger->error("Erro na conexão {$connId}: " . $e->getMessage());
                $this->cleanupConnection($conn);
            });
        });

        // Ping a cada 25 segundos
        $loop->addPeriodicTimer(25, function () {
            foreach (self::$clients as $deviceId => $client) {
                try {
                    $client->write(self::encodeWebSocketFrame('', 0x9)); // Ping
                } catch (Exception $e) {
                    self::$logger->error("Erro ping para {$deviceId}");
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
            self::$logger->info("Processando mensagem de identificação (tamanho: " . strlen($data) . " bytes)");

            $payload = $this->decodeTextFrame($data);

            if ($payload && isset($payload['command']) && $payload['command'] === 'identify') {
                $deviceId = trim($payload['device'] ?? '');

                if (empty($deviceId)) {
                    self::$logger->error("Device ID vazio na identificação");
                    $conn->close();
                    return;
                }

                if (isset(self::$clients[$deviceId])) {
                    self::$logger->error("Device {$deviceId} já conectado. Rejeitando duplicata.");
                    $conn->write(self::encodeWebSocketFrame(json_encode([
                        'status' => 'error',
                        'message' => 'Device already connected'
                    ]), 0x1));
                    $conn->close();
                    return;
                }

                // Registra definitivamente
                self::$clients[$deviceId] = $conn;
                unset(self::$pending[$connId]);

                self::$logger->info("✅ Device identificado com sucesso: {$deviceId}");

                // Envia confirmação
                $conn->write(self::encodeWebSocketFrame(json_encode([
                    'status' => 'identified',
                    'device' => $deviceId
                ]), 0x1));

                self::$logger->info("Confirmação 'identified' enviada para {$deviceId}");
            } else {
                self::$logger->warning("Mensagem inválida antes da identificação. Payload recebido: " . substr(json_encode($payload), 0, 300));
                $conn->close();
            }
            return;
        }

        // Cliente já identificado → mensagem normal
        if (!empty($data)) {
            $this->websocketMessage->sendMessage($conn, self::$clients, $data);
        }
    }

    private function decodeTextFrame(string $frame): ?array
    {
        if (strlen($frame) < 2) {
            return null;
        }

        try {
            $decoded = $this->decodeWebSocketFrame($frame); // método do trait WebSocketUtils

            if (isset($decoded['opcode']) && $decoded['opcode'] === 0x1 && !empty($decoded['payload'])) {
                $json = json_decode($decoded['payload'], true);
                return is_array($json) ? $json : null;
            }
        } catch (Exception $e) {
            self::$logger->debug("Falha ao decodificar frame: " . $e->getMessage());
        }
        return null;
    }

    private function cleanupConnection(ConnectionInterface $conn): void
    {
        $connId = spl_object_id($conn);

        if (isset(self::$pending[$connId])) {
            unset(self::$pending[$connId]);
        }

        $deviceId = array_search($conn, self::$clients, true);
        if ($deviceId !== false) {
            unset(self::$clients[$deviceId]);
        }
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
            try {
                if (empty(self::$clients)) return;

                $devices = array_keys(self::$clients);
                $integrations = $this->integrationService->getWebsocketOpen($devices);

                foreach ($integrations as $integration) {
                    $this->sendToClient($integration);
                }
            } catch (Exception $e) {
                self::$logger->error("Erro ao consumir mensagens: " . $e->getMessage());
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
            } catch (Exception $e) {
                self::$logger->error("Erro ao enviar para {$deviceId}: " . $e->getMessage());
                $this->removeClient($client, $deviceId);
                $this->integrationService->setError($integration);
            }
        }
    }
}
