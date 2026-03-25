<?php

namespace ControleOnline\Service\Server;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\IntegrationService;
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

    private static array $clients = [];           // deviceId => ConnectionInterface
    private static array $pending = [];           // connId => ConnectionInterface

    public function __construct(
        private WebsocketMessage $websocketMessage,
        private IntegrationService $integrationService,
        private LoggerService $loggerService
    ) {
        self::$logger = $loggerService->getLogger('websocket');
    }

    public function init(string $bind, string $port): void
    {
        self::$logger->info("Servidor: Iniciando WebSocket Server no processo " . getmypid());

        $loop = Loop::get();
        $socket = new SocketServer($bind . ':' . $port, [], $loop);

        $socket->on('connection', function (ConnectionInterface $conn) {
            $handshakeDone = false;
            $buffer = '';

            self::$logger->info("Nova conexão recebida de " . $conn->getRemoteAddress());

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer) {
                $buffer .= $data;

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) {
                        return;
                    }

                    $headers = self::parseHeaders($buffer);
                    self::$logger->info("Cabeçalhos recebidos: " . json_encode($headers));

                    $response = self::generateHandshakeResponse($headers);
                    if ($response === null) {
                        self::$logger->error("Handshake falhou");
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;

                    // Remove headers do buffer (deixa apenas dados WebSocket, se vier junto)
                    $pos = strpos($buffer, "\r\n\r\n") + 4;
                    $remaining = substr($buffer, $pos);
                    $buffer = '';

                    $connId = spl_object_id($conn);
                    self::$pending[$connId] = $conn;

                    self::$logger->info("Handshake OK. Aguardando identificação do device.");

                    if (!empty($remaining)) {
                        $this->processIncomingData($conn, $remaining);
                    }
                } else {
                    $this->processIncomingData($conn, $buffer);
                    $buffer = '';
                }
            });

            $conn->on('close', function () use ($conn) {
                $this->handleConnectionClose($conn);
            });

            $conn->on('error', function (Exception $e) use ($conn) {
                self::$logger->error("Erro na conexão: " . $e->getMessage());
                $this->handleConnectionClose($conn);
            });
        });

        // Ping a cada 30 segundos
        $loop->addPeriodicTimer(30, function () {
            foreach (self::$clients as $deviceId => $client) {
                try {
                    $client->write(self::encodeWebSocketFrame('', 0x9)); // Ping
                } catch (Exception $e) {
                    self::$logger->error("Erro ao enviar ping para $deviceId");
                    $this->removeClient($client, $deviceId);
                }
            }
        });

        $this->consumeMessages($loop);
        $loop->run();
    }

    private function processIncomingData(ConnectionInterface $conn, string $data): void
    {
        $connId = spl_object_id($conn);

        // Ainda está aguardando identificação
        if (isset(self::$pending[$connId])) {
            $payload = $this->decodeTextFrame($data);

            if ($payload && isset($payload['command']) && $payload['command'] === 'identify') {
                $deviceId = trim($payload['device'] ?? '');

                if (empty($deviceId)) {
                    self::$logger->error("Device ID vazio na identificação");
                    $conn->close();
                    return;
                }

                // Verifica conexão duplicada
                if (isset(self::$clients[$deviceId])) {
                    self::$logger->error("Conexão duplicada para device: $deviceId");
                    $conn->write(self::encodeWebSocketFrame(json_encode([
                        'status' => 'error',
                        'message' => 'Device already connected'
                    ]), 0x1));
                    $conn->close();
                    return;
                }

                // Registra o cliente
                self::$clients[$deviceId] = $conn;
                unset(self::$pending[$connId]);

                self::$logger->info("Device identificado com sucesso: $deviceId");

                // Confirmação para o client
                $conn->write(self::encodeWebSocketFrame(json_encode([
                    'status' => 'identified',
                    'device' => $deviceId
                ]), 0x1));

                // Se veio payload extra junto
                if (!empty($payload['data'])) {
                    $this->websocketMessage->sendMessage($conn, self::$clients, json_encode($payload['data']));
                }
            } else {
                self::$logger->warning("Mensagem inválida antes da identificação. Fechando conexão.");
                $conn->close();
            }
            return;
        }

        // Cliente já identificado → processa mensagem normal
        if (!empty($data)) {
            $this->websocketMessage->sendMessage($conn, self::$clients, $data);
        }
    }

    /**
     * Decodifica frame WebSocket de texto (opcode 1) simples
     */
    private function decodeTextFrame(string $frame): ?array
    {
        if (strlen($frame) < 2) return null;

        try {
            $decoded = $this->decodeWebSocketFrame($frame); // método do trait WebSocketUtils

            if (isset($decoded['opcode']) && $decoded['opcode'] === 0x1 && isset($decoded['payload'])) {
                $json = json_decode($decoded['payload'], true);
                return is_array($json) ? $json : null;
            }
        } catch (Exception $e) {
            // Frame incompleto ou inválido
        }
        return null;
    }

    private function handleConnectionClose(ConnectionInterface $conn): void
    {
        $connId = spl_object_id($conn);

        if (isset(self::$pending[$connId])) {
            unset(self::$pending[$connId]);
        }

        $deviceId = array_search($conn, self::$clients, true);
        if ($deviceId !== false) {
            self::$logger->info("Conexão fechada para device: $deviceId");
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
                if (empty(self::$clients)) {
                    return;
                }

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
                self::$logger->error("Erro ao enviar mensagem para $deviceId: " . $e->getMessage());
                $this->removeClient($client, $deviceId);
                $this->integrationService->setError($integration);
            }
        }
    }
}
