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
                    if (strpos($buffer, "\r\n\r\n") === false) return;

                    self::$logger->info("RAW REQUEST:\n" . $buffer);

                    $headers = $self::parseHeaders($buffer);
                    self::$logger->info("HEADERS: " . json_encode($headers));

                    $deviceId = null;

                    if (!empty($headers['x-device'])) {
                        $deviceId = trim($headers['x-device']);
                    }

                    if (!$deviceId && isset($headers['get'])) {
                        parse_str(parse_url($headers['get'], PHP_URL_QUERY) ?? '', $queryParams);
                        if (!empty($queryParams['device'])) {
                            $deviceId = $queryParams['device'];
                        }
                    }

                    if (!$deviceId && preg_match('/device=([^&\s]+)/', $buffer, $m)) {
                        $deviceId = urldecode($m[1]);
                    }

                    if (!$deviceId) {
                        self::$logger->error("Device não informado");
                        $conn->close();
                        return;
                    }

                    if (isset(self::$clients[$deviceId])) {
                        self::$clients[$deviceId]->close();
                        unset(self::$clients[$deviceId]);
                    }

                    $response = $self::generateHandshakeResponse($headers);

                    if ($response === null) {
                        self::$logger->error("Handshake falhou");
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;

                    self::$logger->info("Cliente registrado: $deviceId");
                    self::addClient($conn, $deviceId);

                    $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);

                    if (!empty($buffer)) {
                        $this->websocketMessage->sendMessage($conn, self::$clients, $buffer);
                    }

                    return;
                }

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
            });

            $conn->on('close', function () use ($conn, &$deviceId) {
                $clientId = $deviceId ?? array_search($conn, self::$clients, true);
                self::$logger->info("Servidor: Conexão fechada, Client ID: " . ($clientId ?: 'Desconhecido') . ", Endereço: " . $conn->getRemoteAddress() . ", Processo: " . getmypid());
                self::removeClient($conn, $clientId);
            });

            $conn->on('error', function (Exception $e) {
                self::$logger->error("Servidor: Erro na conexão: " . $e->getMessage());
            });
        });

        $socket->on('error', function (Exception $e) {
            self::$logger->error("Servidor: Erro no socket principal: " . $e->getMessage());
        });

        $loop->addPeriodicTimer(30, function () {
            foreach (self::$clients as $clientId => $client) {
                try {
                    $client->write(self::encodeWebSocketFrame('', 0x9));
                } catch (Exception $e) {
                    self::$logger->error("Erro ping $clientId: " . $e->getMessage());
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
                if (empty(self::$clients)) {
                    return;
                }

                $devices = array_keys(self::$clients);
                $integrations = $this->integrationService->getWebsocketOpen($devices);

                foreach ($integrations as $integration) {
                    $this->sendToClient($integration);
                }
            } catch (Exception $e) {
                self::$logger->error("Erro ao consumir mensagem: " . $e->getMessage());
            }
        });
    }

    private function sendToClient(Integration $integration): void
    {
        $device = $integration->getDevice();
        $message = $integration->getBody();
        $deviceId = $device->getDevice();

        if (isset(self::$clients[$deviceId])) {
            $client = self::$clients[$deviceId];
            try {
                $frame = self::encodeWebSocketFrame($message, 0x1);
                $client->write($frame);
                $this->integrationService->setDelivered($integration);
            } catch (Exception $e) {
                self::$logger->error("Erro ao enviar mensagem: " . $e->getMessage());
                self::removeClient($client, $deviceId);
                $this->integrationService->setError($integration);
            }
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
        }
    }

    private static function parseHeaders(string $buffer): array
    {
        $headers = [];
        $lines = explode("\r\n", $buffer);

        foreach ($lines as $index => $line) {
            if ($index === 0 && preg_match('/GET (.+) HTTP/', $line, $m)) {
                $headers['get'] = $m[1];
            }

            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return $headers;
    }
}
