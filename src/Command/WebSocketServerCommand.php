<?php

namespace ControleOnline\Command;

use ControleOnline\Service\WebsocketClient;
use React\EventLoop\Loop;
use React\Socket\Server;
use React\Socket\ConnectionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use SplObjectStorage;

#[AsCommand(
    name: 'websocket:start',
    description: 'Inicia o servidor WebSocket com ReactPHP (com gerenciamento estático de clientes)'
)]
class WebSocketServerCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('port', InputArgument::OPTIONAL, 'Porta para o servidor WebSocket', 8080);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = $input->getArgument('port');
        $output->writeln("Iniciando servidor WebSocket ReactPHP na porta {$port} (com gerenciamento estático de clientes)...");

        $loop = Loop::get();
        $socket = new Server("0.0.0.0:{$port}", $loop);

        // Inicializa a propriedade estática $clients do WebsocketClient
        WebsocketClient::setClients(new SplObjectStorage());

        $socket->on('connection', function (ConnectionInterface $conn) use ($output) {
            $output->writeln("Nova conexão recebida ({$conn->resourceId})");
            $handshakeDone = false;
            $buffer = '';

            $conn->on('data', function ($data) use ($conn, $output, &$handshakeDone, &$buffer) {
                $buffer .= $data;
                $output->writeln("Dados brutos recebidos do cliente {$conn->resourceId}: " . bin2hex($data));

                if (!$handshakeDone) {
                    // Tenta encontrar o final dos headers HTTP (\r\n\r\n)
                    if (strpos($buffer, "\r\n\r\n") !== false) {
                        $headers = [];
                        $headerLines = explode("\r\n", substr($buffer, 0, strpos($buffer, "\r\n\r\n")));

                        // Analisa os headers
                        foreach ($headerLines as $line) {
                            if (strpos($line, ':') !== false) {
                                [$key, $value] = explode(':', $line, 2);
                                $headers[trim(strtolower($key))] = trim($value);
                            }
                        }

                        // Verifica se é uma requisição de upgrade WebSocket
                        if (
                            isset($headers['upgrade']) && strtolower($headers['upgrade']) === 'websocket' &&
                            isset($headers['connection']) && strtolower($headers['connection']) === 'upgrade' &&
                            isset($headers['sec-websocket-key']) && isset($headers['sec-websocket-version']) && $headers['sec-websocket-version'] === '13'
                        ) {

                            $secWebSocketKey = $headers['sec-websocket-key'];
                            $magicString = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
                            $hash = base64_encode(sha1($secWebSocketKey . $magicString, true));
                            $response = "HTTP/1.1 101 Switching Protocols\r\n";
                            $response .= "Upgrade: websocket\r\n";
                            $response .= "Connection: Upgrade\r\n";
                            $response .= "Sec-WebSocket-Accept: " . $hash . "\r\n\r\n";

                            $conn->write($response);
                            $handshakeDone = true;
                            WebsocketClient::addClient($conn); // Adiciona o cliente APÓS o handshake
                            $output->writeln("Nova conexão WebSocket estabelecida! ({$conn->resourceId})");

                            // Remove os headers do buffer para processar dados WebSocket futuros
                            $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);

                            // Processar dados WebSocket iniciais, se houver
                            if (!empty($buffer)) {
                                $output->writeln("Processando dados WebSocket iniciais: " . bin2hex($buffer));
                                $decodedMessage = WebsocketClient::decodeWebSocketFrame($buffer);
                                if ($decodedMessage !== null) {
                                    $output->writeln("Mensagem inicial recebida do cliente {$conn->resourceId}: " . $decodedMessage);
                                    // Retransmitir a mensagem para todos os clientes
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
                    // Lógica para dados WebSocket após o handshake
                    $output->writeln("Processando dados WebSocket: " . bin2hex($buffer));
                    $decodedMessage = WebsocketClient::decodeWebSocketFrame($buffer);
                    if ($decodedMessage !== null) {
                        $output->writeln("Mensagem recebida do cliente {$conn->resourceId}: " . $decodedMessage);
                        // Retransmitir a mensagem para todos os clientes
                        $frame = WebsocketClient::encodeWebSocketFrame($decodedMessage);
                        foreach (WebsocketClient::getClients() as $client) {
                            if ($client !== $conn) {
                                $client->write($frame);
                                $output->writeln("Mensagem retransmitida para cliente {$client->resourceId}: " . $decodedMessage);
                            }
                        }
                        $buffer = ''; // Limpar o buffer após processar
                    } else {
                        $output->writeln("Erro ao decodificar frame WebSocket do cliente {$conn->resourceId}: " . bin2hex($buffer));
                    }
                }
            });

            $conn->on('close', function () use ($conn, $output) {
                WebsocketClient::removeClient($conn); // Remove o cliente ao fechar a conexão
                $output->writeln("Conexão fechada! ({$conn->resourceId})");
            });
        });

        $output->writeln('Servidor WebSocket ReactPHP iniciado (com gerenciamento estático de clientes)!');
        $loop->run();

        return Command::SUCCESS;
    }
}
