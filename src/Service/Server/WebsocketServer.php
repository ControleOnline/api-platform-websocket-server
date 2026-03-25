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
    private static $pendingConnections = []; // Conexões sem ID ainda
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

            self::$logger->info("Servidor: Nova conexão anônima de " . $conn->getRemoteAddress());

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer, &$deviceId, $self) {
                $buffer .= $data;

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) return;

                    $headers = $self::parseHeaders($buffer);
                    $response = $self::generateHandshakeResponse($headers);

                    if ($response === null) {
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;
                    
                    // Adiciona a uma lista temporária
                    $connHash = spl_object_hash($conn);
                    self::$pendingConnections[$connHash] = $conn;

                    $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);
                }

                // Processamento de Frames
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

                    // --- LÓGICA DE IDENTIFICAÇÃO ---
                    $decodedMessage = $self::decodeWebSocketFrame($frame);
                    
                    if ($deviceId === null) {
                        $data = json_decode($decodedMessage, true);
                        // Espera um comando: {"command": "identify", "device": "123"}
                        if (isset($data['command']) && $data['command'] == 'identify' && isset($data['device'])) {
                            $deviceId = trim($data['device']);
                            
                            // Remove de pendentes e adiciona aos clientes oficiais
                            unset(self::$pendingConnections[spl_object_hash($conn)]);
                            self::addClient($conn, $deviceId);
                            
                            $conn->write($self::encodeWebSocketFrame(json_encode(['status' => 'identified', 'device' => $deviceId])));
                        }
                    } else {
                        // Se já identificado, segue o fluxo normal
                        $this->websocketMessage->sendMessage($conn, self::$clients, $frame);
                    }
                }
            });

            $conn->on('close', function () use ($conn, &$deviceId) {
                if ($deviceId) {
                    self::removeClient($conn, $deviceId);
                } else {
                    unset(self::$pendingConnections[spl_object_hash($conn)]);
                }
            });
        });

        // Ping para todos (oficiais e pendentes)
        $loop->addPeriodicTimer(30, function () {
            $all = array_merge(self::$clients, self::$pendingConnections);
            foreach ($all as $id => $client) {
                try {
                    $client->write(self::encodeWebSocketFrame('', 0x9));
                } catch (Exception $e) {
                    $client->end();
                }
            }
        });

        $this->consumeMessages($loop);
        $loop->run();
    }

    // ... (restante dos métodos consumeMessages, sendToClient, addClient, removeClient permanecem similares)
    
    private function consumeMessages($loop): void
    {
        $loop->addPeriodicTimer(1, function () {
            if (empty(self::$clients)) return;
            try {
                $devices = array_keys(self::$clients);
                $integrations = $this->integrationService->getWebsocketOpen($devices);
                foreach ($integrations as $integration) {
                    $this->sendToClient($integration);
                }
            } catch (Exception $e) {
                self::$logger->error("Erro no consumo: " . $e->getMessage());
            }
        });
    }

    private function sendToClient(Integration $integration): void
    {
        $deviceId = $integration->getDevice()->getDevice();
        if (isset(self::$clients[$deviceId])) {
            try {
                $frame = self::encodeWebSocketFrame($integration->getBody(), 0x1);
                self::$clients[$deviceId]->write($frame);
                $this->integrationService->setDelivered($integration);
            } catch (Exception $e) {
                self::removeClient(self::$clients[$deviceId], $deviceId);
            }
        }
    }

    private static function addClient($client, $id) {
        self::$clients[$id] = $client;
        self::$logger->info("Dispositivo identificado: $id");
    }

    private static function removeClient($client, $id) {
        unset(self::$clients[$id]);
        $client->end();
    }
}