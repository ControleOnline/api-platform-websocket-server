<?php

namespace ControleOnline\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use ControleOnline\Service\WebsocketClient;

class WebSocketServerCommand extends Command
{
    protected static $defaultName = 'websocket:start';

    protected function configure()
    {
        $this
            ->setDescription('Starts the WebSocket server')
            ->addArgument('port', InputArgument::OPTIONAL, 'Port to listen on', 8080);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = $input->getArgument('port');
        $socket = new SocketServer("0.0.0.0:{$port}", [], Loop::get());
        $output->writeln("WebSocket server started on port {$port}");

        $socket->on('connection', function (ConnectionInterface $conn) use ($output) {
            $output->writeln("Nova conexão recebida ({$conn->resourceId})");
            $handshakeDone = false;
            $buffer = '';

            $conn->on('data', function ($data) use ($conn, $output, &$handshakeDone, &$buffer) {
                $buffer .= $data;
                $output->writeln("Dados brutos recebidos do cliente {$conn->resourceId}: " . bin2hex($data));

                if (!$handshakeDone) {
                    if (strpos($buffer, "\r\n\r\n") !== false) {
                        $headers = [];
                        $headerLines = explode("\r\n", substr($buffer, 0, strpos($buffer, "\r\n\r\n")));

                        foreach ($headerLines as $line) {
                            if (strpos($line, ':') !== false) {
                                [$key, $value] = explode(':', $line, 2);
                                $headers[trim(strtolower($key))] = trim($value);
                            }
                        }

                        if (isset($headers['upgrade']) && strtolower($headers['upgrade']) === 'websocket' &&
                            isset($headers['connection']) && strtolower($headers['connection']) === 'upgrade' &&
                            isset($headers['sec-websocket-key']) && isset($headers['sec-websocket-version']) && $headers['sec-websocket-version'] === '13') {

                            $secWebSocketKey = $headers['sec-websocket-key'];
                            $magicString = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
                            $hash = base64_encode(sha1($secWebSocketKey . $magicString, true));
                            $response = "HTTP/1.1 101 Switching Protocols\r\n";
                            $response .= "Upgrade: websocket\r\n";
                            $response .= "Connection: Upgrade\r\n";
                            $response .= "Sec-WebSocket-Accept: " . $hash . "\r\n\r\n";

                            $conn->write($response);
                            $handshakeDone = true;
                            WebsocketClient::addClient($conn);
                            $output->writeln("Nova conexão WebSocket estabelecida! ({$conn->resourceId})");

                            $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);

                            if (!empty($buffer)) {
                                $output->writeln("Processando dados WebSocket iniciais: " . bin2hex($buffer));
                                $decodedMessage = WebsocketClient::decodeWebSocketFrame($buffer);
                                if ($decodedMessage !== null) {
                                    $output->writeln("Mensagem inicial recebida do cliente {$conn->resourceId}: " . $decodedMessage);
                                    $frame = WebsocketClient::encodeWebSocketFrame($decodedMessage);
                                    foreach (WebsocketClient::getClients() as $client) {
                                        if ($client !== $conn) {
                                            $client->write($frame);
                                            $output->writeln("Mensagem retransmitida para cliente {$client->resourceId}: " . $decodedMessage);
                                        }
                                    }
                                } else {
                                    $output->writeln("Erro ao decodificar frame inicial do cliente {$conn->resourceId}: " . bin2hex($buffer));
                                }
                            }
                        } else {
                            $output->writeln("Requisição de handshake inválida. Fechando conexão ({$conn->resourceId}).");
                            $conn->close();
                        }
                    }
                } else {
                    $output->writeln("Processando dados WebSocket: " . bin2hex($buffer));
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
                            $output->writeln("Frame incompleto, aguardando mais dados: " . bin2hex($buffer));
                            break;
                        }

                        $frame = substr($buffer, 0, $frameLength);
                        $buffer = substr($buffer, $frameLength);

                        $decodedMessage = WebsocketClient::decodeWebSocketFrame($frame);
                        if ($decodedMessage !== null) {
                            $output->writeln("Mensagem recebida do cliente {$conn->resourceId}: " . $decodedMessage);
                            $frame = WebsocketClient::encodeWebSocketFrame($decodedMessage);
                            foreach (WebsocketClient::getClients() as $client) {
                                if ($client !== $conn) {
                                    $client->write($frame);
                                    $output->writeln("Mensagem retransmitida para cliente {$client->resourceId}: " . $decodedMessage);
                                }
                            }
                        } else {
                            $output->writeln("Erro ao decodificar frame WebSocket do cliente {$conn->resourceId}: " . bin2hex($frame));
                        }
                    }
                }
            });

            $conn->on('close', function () use ($conn, $output) {
                WebsocketClient::removeClient($conn);
                $output->writeln("Conexão fechada! ({$conn->resourceId})");
            });
        });

        $socket->on('error', function (\Exception $e) use ($output) {
            $output->writeln('Erro: ' . $e->getMessage());
        });

        Loop::get()->run();

        return Command::SUCCESS;
    }
}