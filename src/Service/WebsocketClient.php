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
        $self = self::class;

        error_log("Cliente: Iniciando conexão com $host:$port");
        $connector->connect("tcp://{$host}:{$port}")->then(
            function (ConnectionInterface $conn) use ($self, $payload, &$messageSent, &$error, $loop, $host, $port) {
                error_log("Cliente: Conexão TCP estabelecida com $host:$port");

                try {
                    $secWebSocketKey = base64_encode(random_bytes(16));
                    error_log("Cliente: **ANTES** de generateHandshakeRequest");
                    $request = $self::generateHandshakeRequest($host, $port, $secWebSocketKey);
                    error_log("Cliente: **DEPOIS** de generateHandshakeRequest");
                    error_log("Cliente: Tamanho da requisição de handshake: " . strlen($request));

                    if (empty($request) || !is_string($request) || strpos($request, "GET /") === false) {
                        $error = "Cliente: Requisição de handshake inválida ou vazia";
                        error_log($error);
                        $conn->close();
                        return;
                    }

                    $conn->write($request);
                    error_log("Cliente: Requisição de handshake enviada com sucesso");
                } catch (Exception $e) {
                    $error = "Cliente: Erro ao gerar ou enviar handshake: " . $e->getMessage();
                    error_log($error);
                    $conn->close();
                    return;
                }

                $buffer = '';
                $conn->on('data', function ($data) use ($self, $conn, $payload, &$messageSent, &$error, $secWebSocketKey, $loop, &$buffer) {
                    error_log("Cliente: Dados recebidos do servidor (hex): " . bin2hex($data));
                    $buffer .= $data;
                    error_log("Cliente: Buffer (hex): " . bin2hex($buffer));

                    if (strpos($buffer, "\r\n\r\n") === false) {
                        error_log("Cliente: Resposta incompleta, aguardando mais dados");
                        return;
                    }

                    error_log("Cliente: Resposta completa do servidor (hex):\n" . bin2hex($buffer));
                    try {
                        $headers = $self::parseHeaders($buffer);
                        error_log("Cliente: Status Line e Cabeçalhos processados: " . json_encode($headers, JSON_PRETTY_PRINT));

                        $statusLine = explode("\r\n", $buffer)[0];
                        error_log("Cliente: Status Line: $statusLine");

                        if (!preg_match('/HTTP\/1\.1 101/', $statusLine)) {
                            $error = "Cliente: Resposta HTTP inválida: $statusLine";
                            error_log($error);
                            $conn->close();
                            return;
                        }

                        if (
                            !isset($headers['upgrade']) || strtolower($headers['upgrade']) !== 'websocket' ||
                            !isset($headers['connection']) || strtolower($headers['connection']) !== 'upgrade' ||
                            !isset($headers['sec-websocket-accept']) ||
                            $headers['sec-websocket-accept'] !== $self::calculateWebSocketAccept($secWebSocketKey)
                        ) {
                            $error = 'Cliente: Handshake inválido';
                            error_log("Cliente: Erro no handshake. Cabeçalhos: " . json_encode($headers));
                            $conn->close();
                            return;
                        }

                        error_log("Cliente: Handshake bem-sucedido, enviando payload: $payload");
                        $frame = $self::encodeWebSocketFrame($payload);
                        $conn->write($frame);
                        $messageSent = true;

                        $loop->addTimer(5, function () use ($conn) {
                            error_log("Cliente: Fechando conexão após envio");
                            $conn->close();
                        });
                    } catch (Exception $e) {
                        $error = "Cliente: Erro ao processar resposta: " . $e->getMessage();
                        error_log($error);
                        $conn->close();
                    }
                });

                $conn->on('close', function () use (&$error, &$messageSent) {
                    if (!$messageSent && !$error) {
                        $error = 'Cliente: Conexão fechada antes de enviar a mensagem';
                        error_log($error);
                    }
                });

                $conn->on('error', function (Exception $e) use (&$error) {
                    $error = 'Cliente: Erro na conexão: ' . $e->getMessage();
                    error_log($error);
                });
            },
            function (Exception $e) use (&$error) {
                $error = 'Cliente: Falha ao conectar: ' . $e->getMessage();
                error_log($error);
            }
        );

        $loop->addTimer(30, function () use ($loop, &$error, &$messageSent) { // Aumentei o timeout para 30 segundos
            if (!$messageSent && !$error) {
                $error = 'Cliente: Timeout ao enviar mensagem';
                error_log($error);
            }
            $loop->stop();
        });

        try {
            error_log("Cliente: Iniciando loop de eventos");
            $loop->run();
            error_log("Cliente: Loop de eventos finalizado");
        } catch (Exception $e) {
            $error = 'Cliente: Erro no loop de eventos: ' . $e->getMessage();
            error_log($error);
        }

        if (!$messageSent && $error) {
            error_log('Cliente: Falha ao enviar mensagem: ' . $error);
        }
    }
}
