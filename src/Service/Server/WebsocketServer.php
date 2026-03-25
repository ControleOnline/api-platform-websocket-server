<?php

namespace ControleOnline\Service\Server;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\LoggerService;
use ControleOnline\Utils\WebSocketUtils;
use Exception;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

class WebsocketServer
{
    use WebSocketUtils;

    private static $clients = [];
    private static $pendingConnections = [];
    private static $loggerInstance = null;

    public function __construct(
        private WebsocketMessage $websocketMessage,
        private WebsocketClient $websocketClient,
        private IntegrationService $integrationService,
        private LoggerService $loggerService
    ) {
        // Inicialização do logger de forma segura
        self::$loggerInstance = $this->loggerService->getLogger('websocket');
    }

    private static function log($level, $message)
    {
        if (self::$loggerInstance) {
            self::$loggerInstance->$level($message);
        }
    }

    public function init(string $bind, string $port)
    {
        self::log('info', "Servidor: Iniciando servidor no processo " . getmypid());

        $loop = Loop::get();
        $socket = new SocketServer($bind . ':' . $port, [], $loop);

        $socket->on('connection', function (ConnectionInterface $conn) {
            $handshakeDone = false;
            $buffer = '';
            $deviceId = null;
            $connHash = spl_object_hash($conn);

            self::log('info', "Servidor: Nova conexão recebida (anônima) de " . $conn->getRemoteAddress());

            $conn->on('data', function ($data) use ($conn, &$handshakeDone, &$buffer, &$deviceId, $connHash) {
                $buffer .= $data;

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") === false) return;

                    // Handshake simples sem exigir headers de device
                    $headers = $this->parseHeaders($buffer);
                    $response = $this->generateHandshakeResponse($headers);

                    if ($response === null) {
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;

                    // Armazena como pendente até que se identifique
                    self::$pendingConnections[$connHash] = $conn;
                    self::log('info', "Servidor: Handshake concluído. Aguardando identificação...");

                    $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);
                }

                // Processamento de Frames WebSocket
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

                    $totalLength = $payloadOffset + ($masked ? 4 : 0) + $payloadLength;
                    if (strlen($buffer) < $totalLength) break;

                    $frame = substr($buffer, 0, $totalLength);
                    $buffer = substr($buffer, $totalLength);

                    // Decodifica a mensagem recebida do cliente
                    $decoded = $this->decodeWebSocketFrame($frame);

                    // Se o cliente ainda não tem ID, procura o comando de identificação
                    if ($deviceId === null) {
                        $data = json_decode($decoded, true);
                        if (isset($data['command']) && $data['command'] === 'identify' && isset($data['device'])) {
                            $deviceId = trim($data['device']);

                            // Remove de pendentes e adiciona aos oficiais
                            unset(self::$pendingConnections[$connHash]);
                            self::$clients[$deviceId] = $conn;

                            self::log('info', "Servidor: Dispositivo identificado como [$deviceId]");

                            // Responde confirmação ao cliente
                            $conn->write($this->encodeWebSocketFrame(json_encode([
                                'status' => 'identified',
                                'device' => $deviceId
                            ])));
                        }
                    } else {
                        // Se já identificado, trata a mensagem normalmente
                        $this->websocketMessage->sendMessage($conn, self::$clients, $frame);
                    }
                }
            });

            $conn->on('close', function () use ($conn, &$deviceId, $connHash) {
                if ($deviceId && isset(self::$clients[$deviceId])) {
                    unset(self::$clients[$deviceId]);
                    self::log('info', "Servidor: Cliente $deviceId desconectado.");
                }
                unset(self::$pendingConnections[$connHash]);
            });

            $conn->on('error', function (Exception $e) {
                self::log('error', "Servidor: Erro na conexão: " . $e->getMessage());
            });
        });

        // Loop de mensagens do banco de dados
        $this->consumeMessages($loop);

        // Ping periódico para manter sockets abertos
        $loop->addPeriodicTimer(30, function () {
            $all = array_merge(self::$clients, self::$pendingConnections);
            foreach ($all as $client) {
                try {
                    $client->write($this->encodeWebSocketFrame('', 0x9));
                } catch (Exception $e) {
                    $client->close();
                }
            }
        });

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
                self::log('error', "Servidor: Erro ao consumir fila: " . $e->getMessage());
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
                $frame = $this->encodeWebSocketFrame($message, 0x1);
                $client->write($frame);
                $this->integrationService->setDelivered($integration);
                self::log('info', "Servidor: Mensagem entregue para $deviceId");
            } catch (Exception $e) {
                unset(self::$clients[$deviceId]);
                $client->close();
                $this->integrationService->setError($integration);
            }
        }
    }
}
