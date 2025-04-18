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
                error_log("Conexão TCP estabelecida com $host:$port");
                $secWebSocketKey = base64_encode(random_bytes(16));
                $request = $this->generateHandshakeRequest($host, $port, $secWebSocketKey);
                error_log("Enviando handshake:\n$request");
                $conn->write($request);

                $buffer = '';
                $conn->on('data', function ($data) use ($conn, $payload, &$messageSent, &$error, $secWebSocketKey, $loop, &$buffer) {
                    $buffer .= $data;
                    error_log("Dados recebidos:\n$data");
                    if (strpos($buffer, "\r\n\r\n") === false) {
                        error_log("Aguardando mais dados");
                        return;
                    }

                    error_log("Resposta completa:\n$buffer");
                    $headers = $this->parseHeaders($buffer);
                    $statusLine = explode("\r\n", $buffer)[0];
                    error_log("Status Line: $statusLine");
                    error_log("Cabeçalhos processados: " . json_encode($headers));

                    if (!preg_match('/HTTP\/1\.1 101/', $statusLine)) {
                        $error = "Resposta HTTP inválida: $statusLine";
                        error_log($error);
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
                        error_log("Erro no handshake. Cabeçalhos: " . json_encode($headers));
                        $conn->close();
                        return;
                    }

                    error_log("Handshake bem-sucedido, enviando payload: $payload");
                    $frame = chr(0x81) . chr(strlen($payload)) . $payload;
                    $conn->write($frame);
                    $messageSent = true;

                    $loop->addTimer(1, function () use ($conn) {
                        $conn->close();
                    });
                });

                $conn->on('close', function () use (&$error, &$messageSent) {
                    if (!$messageSent && !$error) {
                        $error = 'Conexão fechada antes de enviar a mensagem';
                        error_log($error);
                    }
                });

                $conn->on('error', function (Exception $e) use (&$error) {
                    $error = 'Erro na conexão: ' . $e->getMessage();
                    error_log($error);
                });
            },
            function (Exception $e) use (&$error) {
                $error = 'Falha ao conectar: ' . $e->getMessage();
                error_log($error);
            }
        );

        $loop->addTimer(10, function () use ($loop, &$error, &$messageSent) {
            if (!$messageSent && !$error) {
                $error = 'Timeout ao enviar mensagem';
                error_log($error);
            }
            $loop->stop();
        });

        $loop->run();

        if (!$messageSent && $error) {
            error_log('Falha ao enviar mensagem: ' . $error);
        }
    }
}