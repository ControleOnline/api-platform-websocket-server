<?php

namespace ControleOnline\Service;

use ControleOnline\Utils\WebSocketUtils;
use Exception;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

class WebsocketClient
{
    use WebSocketUtils;

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

                $secWebSocketKey = base64_encode(random_bytes(16));
                $conn->write($this->generateHandshakeRequest($host, $port, $secWebSocketKey));


                $conn->on('data', function ($data) use ($conn, $payload, &$messageSent, &$error, $secWebSocketKey, $loop) {
                    if (strpos($data, "\r\n\r\n") === false) {
                        return;
                    }

                    $headers = $this->parseHeaders($data);
                    $statusLine = explode("\r\n", $data)[0];

                    if (!preg_match('/HTTP\/1\.1 101/', $statusLine)) {
                        $error = 'Resposta HTTP inválida';
                        $conn->close();
                        return;
                    }

                    if (
                        !isset($headers['upgrade']) || strtolower($headers['upgrade']) !== 'websocket' ||
                        !isset($headers['connection']) || strtolower($headers['connection']) !== 'upgrade' ||
                        !isset($headers['sec-websocket-accept']) ||
                        $headers['sec-websocket-accept'] !== $this->calculateWebSocketAccept($secWebSocketKey)
                    ) {
                        $error = 'Handshake inválido';
                        $conn->close();
                        return;
                    }

                    // Envia o payload como quadro de texto WebSocket (opcode 0x1)
                    $frame = chr(0x81) . chr(strlen($payload)) . $payload;
                    $conn->write($frame);
                    $messageSent = true;

                    // Fecha a conexão após 1 segundo
                    $loop->addTimer(1, function () use ($conn) {
                        $conn->close();
                    });
                });

                $conn->on('close', function () use (&$error, &$messageSent) {
                    if (!$messageSent && !$error) {
                        $error = 'Conexão fechada antes de enviar a mensagem';
                    }
                });

                $conn->on('error', function (Exception $e) use (&$error) {
                    $error = 'Erro na conexão: ' . $e->getMessage();
                });
            },
            function (Exception $e) use (&$error) {
                $error = 'Falha ao conectar: ' . $e->getMessage();
            }
        );

        // Define um timeout de 5 segundos
        $loop->addTimer(5, function () use ($loop, &$error, &$messageSent) {
            if (!$messageSent && !$error) {
                $error = 'Timeout ao enviar mensagem';
            }
            $loop->stop();
        });

        $loop->run();

        if (!$messageSent && $error) {
            error_log('Falha ao enviar mensagem: ' . $error);
        }
    }
}
