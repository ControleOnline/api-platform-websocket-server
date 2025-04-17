<?php

namespace ControleOnline\Service;

use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class WebsocketClient
{
    private static $clients = [];

    private $entityManager;
    private $requestStack;
    private $logger;

    public function __construct(EntityManagerInterface $entityManager = null, RequestStack $requestStack = null, LoggerInterface $logger = null)
    {
        $this->entityManager = $entityManager;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }

    public static function addClient(ConnectionInterface $client): void
    {
        self::$clients[$client->resourceId] = $client;
    }

    public static function removeClient(ConnectionInterface $client): void
    {
        unset(self::$clients[$client->resourceId]);
    }

    public static function getClients(): array
    {
        return self::$clients;
    }

    public static function sendMessage(string $type, string $message): void
    {
        $payload = json_encode(['type' => $type, 'message' => $message]);
        self::sendMessageToAll($payload);
    }

    private static function sendMessageToAll(string $payload): void
    {
        $loop = Loop::get();
        $connector = new Connector($loop);

        $host = '127.0.0.1';
        $port = 8080;

        $messageSent = false;
        $error = null;

        $connector->connect("tcp://{$host}:{$port}")->then(
            function (ConnectionInterface $conn) use ($payload, &$messageSent, &$error, $loop, $host, $port) {
                error_log('Conexão estabelecida com o servidor WebSocket');

                $secWebSocketKey = base64_encode(random_bytes(16));
                $handshakeRequest = "GET / HTTP/1.1\r\n"
                    . "Host: {$host}:{$port}\r\n"
                    . "Upgrade: websocket\r\n"
                    . "Connection: Upgrade\r\n"
                    . "Sec-WebSocket-Key: {$secWebSocketKey}\r\n"
                    . "Sec-WebSocket-Version: 13\r\n\r\n";

                $conn->write($handshakeRequest);
                error_log('Handshake enviado: ' . $handshakeRequest);

                $buffer = '';
                $handshakeDone = false;

                $conn->on('data', function ($data) use ($conn, $payload, &$buffer, &$handshakeDone, &$messageSent, &$error, $secWebSocketKey, $loop) {
                    $buffer .= $data;
                    error_log('Dados recebidos: ' . $data);

                    if (!$handshakeDone && strpos($buffer, "\r\n\r\n") !== false) {
                        $headers = explode("\r\n", $buffer);
                        $statusLine = array_shift($headers);
                        $isValidHandshake = false;
                        $secWebSocketAccept = null;

                        if (preg_match('/HTTP\/1\.1 101/', $statusLine)) {
                            $hasUpgrade = false;
                            $hasConnection = false;

                            foreach ($headers as $header) {
                                if (preg_match('/^Upgrade:\s*websocket/i', $header)) {
                                    $hasUpgrade = true;
                                }
                                if (preg_match('/^Connection:\s*Upgrade/i', $header)) {
                                    $hasConnection = true;
                                }
                                if (preg_match('/^Sec-WebSocket-Accept:\s*(.+)/i', $header, $matches)) {
                                    $secWebSocketAccept = trim($matches[1]);
                                }
                            }

                            $expectedAccept = base64_encode(sha1($secWebSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                            if ($hasUpgrade && $hasConnection && $secWebSocketAccept === $expectedAccept) {
                                $isValidHandshake = true;
                            } else {
                                $error = 'Validação do handshake falhou: ';
                                if (!$hasUpgrade) {
                                    $error .= 'Cabeçalho Upgrade ausente ou inválido; ';
                                }
                                if (!$hasConnection) {
                                    $error .= 'Cabeçalho Connection ausente ou inválido; ';
                                }
                                if ($secWebSocketAccept !== $expectedAccept) {
                                    $error .= "Sec-WebSocket-Accept inválido (esperado: $expectedAccept, recebido: $secWebSocketAccept); ";
                                }
                            }
                        } else {
                            $error = 'Resposta HTTP inválida: ' . $statusLine;
                        }

                        if ($isValidHandshake) {
                            $handshakeDone = true;
                            error_log('Handshake WebSocket bem-sucedido');
                            $frame = self::encodeWebSocketFrame($payload);
                            $conn->write($frame);
                            error_log('Mensagem enviada: ' . $payload);
                            $messageSent = true;

                            $loop->addTimer(1, function () use ($conn) {
                                $conn->close();
                                error_log('Conexão fechada após atraso');
                            });
                        } else {
                            error_log($error);
                            $conn->close();
                        }
                    }
                });

                $conn->on('close', function () use (&$error, &$messageSent) {
                    error_log('Conexão com o servidor WebSocket fechada');
                    if (!$messageSent && !$error) {
                        $error = 'Conexão fechada antes de enviar a mensagem';
                    }
                });

                $conn->on('error', function (\Exception $e) use (&$error) {
                    $error = 'Erro na conexão WebSocket: ' . $e->getMessage();
                    error_log($error);
                });
            },
            function (\Exception $e) use (&$error) {
                $error = 'Falha ao conectar ao servidor WebSocket: ' . $e->getMessage();
                error_log($error);
            }
        );

        $timeout = 5;
        $loop->addTimer($timeout, function () use ($loop, &$error, &$messageSent) {
            if (!$messageSent && !$error) {
                $error = 'Timeout atingido ao tentar enviar mensagem';
                error_log($error);
            }
            $loop->stop();
        });

        $loop->run();

        if (!$messageSent) {
            error_log('Falha ao enviar mensagem: ' . ($error ?: 'Erro desconhecido'));
        }
    }

    public static function encodeWebSocketFrame(string $payload, int $opcode = 0x1): string
    {
        $frameHead = [];
        $payloadLength = strlen($payload);

        $frameHead[0] = 0x80 | $opcode;

        $mask = true;
        $maskingKey = $mask ? random_bytes(4) : '';

        if ($payloadLength > 65535) {
            $frameHead[1] = ($mask ? 0x80 : 0) | 0x7F;
            $frameHead[2] = ($payloadLength >> 56) & 0xFF;
            $frameHead[3] = ($payloadLength >> 48) & 0xFF;
            $frameHead[4] = ($payloadLength >> 40) & 0xFF;
            $frameHead[5] = ($payloadLength >> 32) & 0xFF;
            $frameHead[6] = ($payloadLength >> 24) & 0xFF;
            $frameHead[7] = ($payloadLength >> 16) & 0xFF;
            $frameHead[8] = ($payloadLength >> 8) & 0xFF;
            $frameHead[9] = $payloadLength & 0xFF;
        } elseif ($payloadLength > 125) {
            $frameHead[1] = ($mask ? 0x80 : 0) | 0x7E;
            $frameHead[2] = ($payloadLength >> 8) & 0xFF;
            $frameHead[3] = $payloadLength & 0xFF;
        } else {
            $frameHead[1] = ($mask ? 0x80 : 0) | $payloadLength;
        }

        $maskedPayload = $payload;
        if ($mask) {
            for ($i = 0; $i < $payloadLength; $i++) {
                $maskedPayload[$i] = $payload[$i] ^ $maskingKey[$i % 4];
            }
        }

        error_log('Enviando frame WebSocket: ' . bin2hex(pack('C*', ...$frameHead) . ($mask ? $maskingKey : '') . $maskedPayload));
        return pack('C*', ...$frameHead) . ($mask ? $maskingKey : '') . $maskedPayload;
    }

    public static function decodeWebSocketFrame(string $data): ?string
    {
        error_log('Decodificando frame WebSocket: ' . bin2hex($data));
        $unmaskedPayload = '';
        $payloadOffset = 2;
        $masked = (ord($data[1]) >> 7) & 0x1;
        $payloadLength = ord($data[1]) & 0x7F;

        error_log("Máscara: $masked, Comprimento do payload: $payloadLength");

        if ($payloadLength === 126) {
            $payloadOffset = 4;
            $payloadLength = unpack('n', substr($data, 2, 2))[1];
            error_log("Payload estendido (16 bits): $payloadLength");
        } elseif ($payloadLength === 127) {
            $payloadOffset = 10;
            $payloadLength = unpack('J', substr($data, 2, 8))[1];
            error_log("Payload estendido (64 bits): $payloadLength");
        }

        if ($masked) {
            if (strlen($data) < $payloadOffset + 4 + $payloadLength) {
                error_log('Frame incompleto: tamanho insuficiente');
                return null;
            }
            $maskingKey = substr($data, $payloadOffset, 4);
            $payload = substr($data, $payloadOffset + 4, $payloadLength);
            error_log('Chave de máscara: ' . bin2hex($maskingKey));
            for ($i = 0; $i < $payloadLength; $i++) {
                $unmaskedPayload .= $payload[$i] ^ $maskingKey[$i % 4];
            }
        } else {
            if (strlen($data) < $payloadOffset + $payloadLength) {
                error_log('Frame incompleto: tamanho insuficiente (sem máscara)');
                return null;
            }
            $unmaskedPayload = substr($data, $payloadOffset, $payloadLength);
        }

        error_log('Payload decodificado: ' . $unmaskedPayload);
        return $unmaskedPayload;
    }
}
