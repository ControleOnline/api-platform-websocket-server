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

    public function init(?string $bind = '0.0.0.0', ?string $port = '8080')
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
                    $headers = $self::parseHeaders($buffer);
                    self::$logger->info("Servidor: Cabeçalhos recebidos: " . json_encode($headers));
                    if (!isset($headers['x-device']) || empty(trim($headers['x-device']))) {
                        self::$logger->error("Servidor: Cabeçalho x-device ausente ou inválido");
                        $conn->close();
                        return;
                    }
                    $deviceId = trim($headers['x-device']);
                    self::$logger->info("Servidor: Device ID: $deviceId");

                    $response = $self::generateHandshakeResponse($headers);
                    if ($response === null) {
                        self::$logger->error("Servidor: Handshake falhou");
                        $conn->close();
                        return;
                    }

                    // Verificar conexão duplicada antes de aceitar
                    if (isset(self::$clients[$deviceId])) {
                        self::$logger->error("Servidor: Conexão duplicada para $deviceId. Rejeitando nova conexão.");
                        $conn->write("HTTP/1.1 409 Conflict\r\n\r\nDevice already connected");
                        $conn->close();
                        return;
                    }

                    $conn->write($response);
                    $handshakeDone = true;

                    self::$logger->info("Servidor: Registrando cliente com ID: $deviceId");
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

        // Adicionar ping para detectar conexões inativas
        $loop->addPeriodicTimer(30, function () {
            foreach (self::$clients as $clientId => $client) {
                try {
                    $client->write(self::encodeWebSocketFrame('', 0x9)); // Ping frame
                    self::$logger->info("Servidor: Enviando ping para $clientId");
                } catch (Exception $e) {
                    self::$logger->error("Servidor: Erro ao enviar ping para $clientId: " . $e->getMessage());
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
                    self::$logger->info("Servidor: Nenhum cliente conectado. Ignorando busca de mensagens.");
                    return;
                }

                $devices = array_keys(self::$clients);
                $integrations = $this->integrationService->getWebsocketOpen($devices);
                self::$logger->info("Servidor: Mensagens recebidas: " . count($integrations) . ", Processo: " . getmypid());

                foreach ($integrations as $integration)
                    $this->sendToClient($integration);
            } catch (Exception $e) {
                self::$logger->error("Servidor: Erro ao consumir mensagem: " . $e->getMessage());
            }
        });
    }

    private function sendToClient(Integration $integration): void
    {
        $device = $integration->getDevice();
        $message = $integration->getBody();
        $deviceId = $device->getDevice();
        self::$logger->info("Servidor: Tentando enviar para dispositivo: $deviceId, Tamanho da mensagem: " . strlen($message) . " bytes, Processo: " . getmypid());
        self::$logger->info("Servidor: Conteúdo da mensagem: " . $message);

        if (isset(self::$clients[$deviceId])) {
            $client = self::$clients[$deviceId];
            try {
                $frame = self::encodeWebSocketFrame($message, 0x1);
                self::$logger->info("Servidor: Frame gerado para $deviceId, Tamanho do frame: " . strlen($frame) . " bytes");
                $client->write($frame);
                self::$logger->info("Servidor: Frame enviado para $deviceId");
                self::$logger->info("Servidor: Chamando setDelivered para integration ID: " . ($integration->getId() ?? 'N/A'));
                try {
                    $this->integrationService->setDelivered($integration);
                    self::$logger->info("Servidor: Mensagem marcada como entregue para $deviceId");
                } catch (Exception $e) {
                    self::$logger->error("Servidor: Erro ao marcar mensagem como entregue para $deviceId: " . $e->getMessage() . ", Trace: " . $e->getTraceAsString());
                }
            } catch (Exception $e) {
                self::$logger->error("Servidor: Erro ao enviar mensagem para $deviceId: " . $e->getMessage() . ", Trace: " . $e->getTraceAsString());
                self::removeClient($client, $deviceId);
                $this->integrationService->setError($integration);
            }
        } else {
            self::$logger->error("Servidor: Dispositivo $deviceId não está conectado.");
        }
    }

    private static function addClient(ConnectionInterface $client, string $clientId): void
    {
        self::$clients[$clientId] = $client;
        self::$logger->info("Servidor: Cliente adicionado: $clientId, Total: " . count(self::$clients) . ", Clientes: " . json_encode(array_keys(self::$clients)) . ", Processo: " . getmypid());
    }

    private static function removeClient(ConnectionInterface $client, ?string $clientId): void
    {
        if ($clientId && isset(self::$clients[$clientId])) {
            unset(self::$clients[$clientId]);
            self::$logger->info("Servidor: Cliente removido: $clientId, Total: " . count(self::$clients) . ", Clientes: " . json_encode(array_keys(self::$clients)) . ", Processo: " . getmypid());
        } else {
            self::$logger->error("Servidor: Cliente não encontrado na lista (Endereço: " . $client->getRemoteAddress() . "), Processo: " . getmypid());
        }
    }
}
