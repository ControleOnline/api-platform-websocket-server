<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Utils\WebSocketUtils;
use Exception;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

class WebsocketClient
{
    use WebSocketUtils;

    public static function sendMessage($message): void
    {
        $payload = json_encode([$message]);
            self::sendBroadcast($payload);
      }

    private static function sendBroadcast(string $payload): void
    {
        $loop = Loop::get();
        $connector = new Connector($loop);

        $host = '127.0.0.1';
        $port = 8080;
        $messageSent = false;
        $error = null;
        $self = self::class;

        error_log("Cliente PHP: Iniciando conexão com $host:$port");
        $connector->connect("tcp://{$host}:{$port}")->then(
            function (ConnectionInterface $conn) use ($self, $payload, &$messageSent, &$error, $loop, $host, $port) {
                error_log("Cliente PHP: Conexão TCP estabelecida com $host:$port");

                try {
                    $secWebSocketKey = base64_encode(random_bytes(16));
                    $request = $self::generateHandshakeRequest($host, $port, $secWebSocketKey);

                    if (empty($request) || !is_string($request) || strpos($request, "GET /") === false) {
                        $error = "Cliente PHP: Requisição de handshake inválida ou vazia";
                        error_log($error);
                        $conn->close();
                        return;
                    }

                    $conn->write($request);
                    error_log("Cliente PHP: Requisição de handshake enviada com sucesso");
                } catch (Exception $e) {
                    $error = "Cliente PHP: Erro ao gerar ou enviar handshake: " . $e->getMessage();
                    error_log($error);
                    $conn->close();
                    return;
                }

                $buffer = '';
                $conn->on('data', function ($data) use ($self, $conn, $payload, &$messageSent, &$error, $secWebSocketKey, $loop, &$buffer) {
                    error_log("Cliente PHP: Dados recebidos do servidor (hex): " . bin2hex($data));
                    $buffer .= $data;

                    if (strpos($buffer, "\r\n\r\n") === false) {
                        return;
                    }

                    try {
                        $headers = $self::parseHeaders($buffer);
                        $statusLine = explode("\r\n", $buffer)[0];

                        if (!preg_match('/HTTP\/1\.1 101/', $statusLine)) {
                            $error = "Cliente PHP: Resposta HTTP inválida: $statusLine";
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
                            $error = 'Cliente PHP: Handshake inválido';
                            error_log("Cliente PHP: Erro no handshake. Cabeçalhos: " . json_encode($headers));
                            $conn->close();
                            return;
                        }

                        $frame = $self::encodeWebSocketFrame($payload);
                        $conn->write($frame);
                        $messageSent = true;

                        $loop->addTimer(5, function () use ($conn) {
                            $conn->close();
                        });
                    } catch (Exception $e) {
                        $error = "Cliente PHP: Erro ao processar resposta: " . $e->getMessage();
                        error_log($error);
                        $conn->close();
                    }
                });

                $conn->on('close', function () use (&$error, &$messageSent) {
                    if (!$messageSent && !$error) {
                        $error = 'Cliente PHP: Conexão fechada antes de enviar a mensagem';
                        error_log($error);
                    }
                });

                $conn->on('error', function (Exception $e) use (&$error) {
                    $error = 'Cliente PHP: Erro na conexão: ' . $e->getMessage();
                    error_log($error);
                });
            },
            function (Exception $e) use (&$error) {
                $error = 'Cliente PHP: Falha ao conectar: ' . $e->getMessage();
                error_log($error);
            }
        );

        $loop->addTimer(30, function () use ($loop, &$error, &$messageSent) {
            if (!$messageSent && !$error) {
                $error = 'Cliente PHP: Timeout ao enviar mensagem';
                error_log($error);
            }
            $loop->stop();
        });

        try {
            $loop->run();
        } catch (Exception $e) {
            $error = 'Cliente PHP: Erro no loop de eventos: ' . $e->getMessage();
            error_log($error);
        }

        if (!$messageSent && $error) {
            error_log('Cliente PHP: Falha ao enviar mensagem: ' . $error);
        }
    }
}
