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

    private static $clients = [];
    private static $logger;

    public function __construct(
        private WebsocketMessage $websocketMessage,
        private WebsocketClient $websocketClient,
        private IntegrationService $integrationService,
        private LoggerService $loggerService
    ) {
        self::$logger = $loggerService->getLogger('websocket');
    }

    public function init(string $bind, string $port)
    {
        self::$logger->info("Servidor: Iniciando servidor no processo " . getmypid());
        $loop = Loop::get();
        $socket = new SocketServer($bind . ':' . $port, [], $loop);

        $socket->on('connection', function (ConnectionInterface $conn) {
            $handshakeDone = false;
            $buffer = '';
            $deviceId = null;
            $self = self::class;

            self::$logger->info("Servidor: Nova conexão recebida de " . $conn->getRemoteAddress());

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer, &$deviceId, $self) {
                $buffer .= $data;

                if (!$handshakeDone) {
                    // Aguarda o fim do cabeçalho HTTP (\r\n\r\n)
                    if (strpos($buffer, "\r\n\r\n") === false) return;

                    $headers = $self::parseHeaders($buffer);

                    // --- CORREÇÃO: Tentar extrair o ID da URL se não estiver no Header ---
                    if (!isset($headers['x-device']) || empty(trim($headers['x-device']))) {
                        if (preg_match('/GET\s+([^\s?]+)(?:\?([^\s]*))?\s+HTTP/i', $buffer, $matches)) {
                            $queryString = $matches[2] ?? '';
                            parse_str($queryString, $queryParams);

                            // Aceita tanto 'device' quanto 'x-device' na URL
                            $foundId = $queryParams['device'] ?? $queryParams['x-device'] ?? null;

                            if ($foundId) {
                                $headers['x-device'] = $foundId;
                                self::$logger->info("Servidor: Device ID extraído da URL: $foundId");
                            }
                        }
                    }

                    // Validação final do Device ID
                    if (!isset($headers['x-device']) || empty(trim($headers['x-device']))) {
                        self::$logger->error("Servidor: Cabeçalho ou parâmetro x-device ausente. Buffer: " . substr($buffer, 0, 100));
                        $conn->close();
                        return;
                    }

                    $deviceId = trim($headers['x-device']);
                    $response = $self::generateHandshakeResponse($headers);

                    if ($response === null) {
                        self::$logger->error("Servidor: Handshake falhou para $deviceId");
                        $conn->close();
                        return;
                    }

                    // Verificar conexão duplicada
                    if (isset(self::$clients[$deviceId])) {
                        self::$logger->error("Servidor: Conexão duplicada para $deviceId. Rejeitando.");
                        $conn->write("HTTP/1.1 409 Conflict\r\n\r\nDevice already connected");
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;

                    self::addClient($conn, $deviceId);

                    // Processar dados remanescentes no buffer após o handshake
                    $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);
                    if (!empty($buffer)) {
                        $this->websocketMessage->sendMessage($conn, self::$clients, $buffer);
                    }
                } else {
                    // Processamento de frames WebSocket (Mensagens após handshake)
                    while (strlen($buffer) >= 2) {
                        $secondByte = ord($buffer[1]);
                        $masked = ($secondByte >> 7) & 0x1;
                        $payloadLength = $secondByte & 0x7F;
                        $payloadOffset = 2;

                        if ($payloadLength === 126) {
                            if (strlen($buffer) < 4) break;
                            $payloadOffset = 4;
                            $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
                        } elseif ($payloadLength === 127) {
                            if (strlen($buffer) < 10) break;
                            $payloadOffset = 10;
                            $payloadLength = unpack('J', substr($buffer, 2, 8))[1];
                        }

                        $frameLength = $payloadOffset + ($masked ? 4 : 0) + $payloadLength;
                        if (strlen($buffer) < $frameLength) break;

                        $frame = substr($buffer, 0, $frameLength);
                        $buffer = substr($buffer, $frameLength);
                        $this->websocketMessage->sendMessage($conn, self::$clients, $frame);
                    }
                }
            });

            $conn->on('close', function () use ($conn, &$deviceId) {
                $clientId = $deviceId ?? array_search($conn, self::$clients, true);
                self::$logger->info("Servidor: Conexão fechada: " . ($clientId ?: 'Desconhecido'));
                self::removeClient($conn, $clientId);
            });

            $conn->on('error', function (Exception $e) {
                self::$logger->error("Servidor: Erro na conexão: " . $e->getMessage());
            });
        });

        // Ping periódico para manter conexões vivas
        $loop->addPeriodicTimer(30, function () {
            foreach (self::$clients as $clientId => $client) {
                try {
                    $client->write(self::encodeWebSocketFrame('', 0x9)); // Opcode 0x9 = Ping
                } catch (Exception $e) {
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
                if (empty(self::$clients)) return;

                $devices = array_keys(self::$clients);
                $integrations = $this->integrationService->getWebsocketOpen($devices);

                foreach ($integrations as $integration) {
                    $this->sendToClient($integration);
                }
            } catch (Exception $e) {
                self::$logger->error("Servidor: Erro ao consumir fila: " . $e->getMessage());
            }
        });
    }

    private function sendToClient(Integration $integration): void
    {
        $deviceId = $integration->getDevice()->getDevice();
        $message  = $integration->getBody();

        if (isset(self::$clients[$deviceId])) {
            $client = self::$clients[$deviceId];
            try {
                $frame = self::encodeWebSocketFrame($message, 0x1); // Opcode 0x1 = Text
                $client->write($frame);
                $this->integrationService->setDelivered($integration);
                self::$logger->info("Servidor: Mensagem enviada e confirmada para $deviceId");
            } catch (Exception $e) {
                self::$logger->error("Servidor: Falha ao enviar para $deviceId: " . $e->getMessage());
                self::removeClient($client, $deviceId);
                $this->integrationService->setError($integration);
            }
        }
    }

    private static function addClient(ConnectionInterface $client, string $clientId): void
    {
        self::$clients[$clientId] = $client;
        self::$logger->info("Servidor: Cliente conectado: $clientId. Total: " . count(self::$clients));
    }

    private static function removeClient(ConnectionInterface $client, ?string $clientId): void
    {
        if ($clientId && isset(self::$clients[$clientId])) {
            unset(self::$clients[$clientId]);
            self::$logger->info("Servidor: Cliente removido: $clientId");
        }
        $client->end();
    }
}
