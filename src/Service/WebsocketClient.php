<?php

namespace ControleOnline\Service;

use React\Socket\Connector;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use Doctrine\ORM\EntityManagerInterface;
use SplObjectStorage;
use Symfony\Component\HttpFoundation\RequestStack;

class WebsocketClient
{
    private static $clients;

    public function __construct(
        private EntityManagerInterface $manager,
        private RequestStack $requestStack
    ) {}

    public static function setClients(SplObjectStorage $clients): void
    {
        self::$clients = $clients;
    }

    public static function sendMessage(string $type, $message): void
    {
        $payload = json_encode(['type' => $type, 'message' => $message]);
        self::sendMessageToAll($payload);
    }

    public static function addClient(ConnectionInterface $client): void
    {
        if (self::$clients) {
            self::$clients->attach($client);
        }
    }

    public static function removeClient(ConnectionInterface $client): void
    {
        if (self::$clients) {
            self::$clients->detach($client);
        }
    }

    public static function getClients(): ?SplObjectStorage
    {
        return self::$clients;
    }

    private static function sendMessageToAll(string $payload): void
    {
        $loop = Loop::get();
        $connector = new Connector($loop);

        // Configuração do servidor WebSocket
        $host = '127.0.0.1';
        $port = 8080;

        // Flags para rastrear o estado
        $messageSent = false;
        $error = null;

        $connector->connect("tcp://{$host}:{$port}")->then(
            function (ConnectionInterface $conn) use ($payload, &$messageSent, &$error, $host, $port) {
                error_log('Conexão estabelecida com o servidor WebSocket');

                // Realizar o handshake WebSocket como cliente
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

                $conn->on('data', function ($data) use ($conn, $payload, &$buffer, &$handshakeDone, &$messageSent, &$error) {
                    $buffer .= $data;
                    error_log('Dados recebidos: ' . $data);

                    if (!$handshakeDone && strpos($buffer, "\r\n\r\n") !== false) {
                        $headers = explode("\r\n", $buffer);
                        $statusLine = array_shift($headers);
                        $isValidHandshake = false;

                        if (preg_match('/HTTP\/1\.1 101/', $statusLine)) {
                            foreach ($headers as $header) {
                                if (
                                    stripos($header, 'Upgrade: websocket') !== false &&
                                    stripos($header, 'Connection: Upgrade') !== false
                                ) {
                                    $isValidHandshake = true;
                                    break;
                                }
                            }
                        }

                        if ($isValidHandshake) {
                            $handshakeDone = true;
                            error_log('Handshake WebSocket bem-sucedido');
                            $frame = self::encodeWebSocketFrame($payload);
                            $conn->write($frame);
                            error_log('Mensagem enviada: ' . $payload);
                            $messageSent = true;
                            $conn->close();
                        } else {
                            $error = 'Falha no handshake WebSocket';
                            error_log($error . ': ' . $buffer);
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

        // Adicionar um timer para limitar a execução do loop
        $timeout = 5; // Timeout de 5 segundos
        $loop->addTimer($timeout, function () use ($loop, &$error, &$messageSent) {
            if (!$messageSent && !$error) {
                $error = 'Timeout atingido ao tentar enviar mensagem';
                error_log($error);
            }
            $loop->stop(); // Parar o loop explicitamente
        });

        // Executar o loop até que a mensagem seja enviada ou ocorra um erro
        $loop->run();

        if (!$messageSent) {
            error_log('Falha ao enviar mensagem: ' . ($error ?: 'Erro desconhecido'));
        }
    }

    private static function encodeWebSocketFrame(string $payload, int $opcode = 0x1): string
    {
        $frameHead = [];
        $payloadLength = strlen($payload);

        $frameHead[0] = 0x80 | $opcode;

        if ($payloadLength > 65535) {
            $frameHead[1] = 0x7F;
            $frameHead[2] = ($payloadLength >> 56) & 0xFF;
            $frameHead[3] = ($payloadLength >> 48) & 0xFF;
            $frameHead[4] = ($payloadLength >> 40) & 0xFF;
            $frameHead[5] = ($payloadLength >> 32) & 0xFF;
            $frameHead[6] = ($payloadLength >> 24) & 0xFF;
            $frameHead[7] = ($payloadLength >> 16) & 0xFF;
            $frameHead[8] = ($payloadLength >> 8) & 0xFF;
            $frameHead[9] = $payloadLength & 0xFF;
        } elseif ($payloadLength > 125) {
            $frameHead[1] = 0x7E;
            $frameHead[2] = ($payloadLength >> 8) & 0xFF;
            $frameHead[3] = $payloadLength & 0xFF;
        } else {
            $frameHead[1] = $payloadLength;
        }

        return pack('C*', ...$frameHead) . $payload;
    }

    public static function decodeWebSocketFrame(string $data): ?string
    {
        $unmaskedPayload = '';
        $payloadOffset = 2;
        $masked = (ord($data[1]) >> 7) & 0x1;
        $payloadLength = ord($data[1]) & 0x7F;

        if ($payloadLength === 126) {
            $payloadOffset = 4;
            $payloadLength = unpack('n', substr($data, 2, 2))[1];
        } elseif ($payloadLength === 127) {
            $payloadOffset = 10;
            $payloadLength = unpack('J', substr($data, 2, 8))[1];
        }

        if ($masked) {
            if (strlen($data) < $payloadOffset + 4 + $payloadLength) {
                return null; // Frame incompleto
            }
            $maskingKey = substr($data, $payloadOffset, 4);
            $payload = substr($data, $payloadOffset + 4, $payloadLength);
            for ($i = 0; $i < $payloadLength; $i++) {
                $unmaskedPayload .= $payload[$i] ^ $maskingKey[$i % 4];
            }
        } else {
            if (strlen($data) < $payloadOffset + $payloadLength) {
                return null; // Frame incompleto
            }
            $unmaskedPayload = substr($data, $payloadOffset, $payloadLength);
        }

        return $unmaskedPayload;
    }
}
