<?php

namespace ControleOnline\Service\Server;

use ControleOnline\Service\DeviceService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Utils\WebSocketUtils;
use React\Socket\ConnectionInterface;

class WebsocketMessage
{
    use WebSocketUtils;

    public function __construct(
        private DeviceService $deviceService,
        private LoggerService $loggerService,
    ) {
        self::$logger = $loggerService->getLogger('websocket');
    }

    /**
     * Envia uma mensagem recebida para os clientes destino
     */
    public function sendMessage(ConnectionInterface $sender, array $clients, string $frame): void
    {
        self::$logger->error("Servidor: Recebido frame para broadcast (hex): " . bin2hex($frame));

        $decodedMessage = $this->decodeWebSocketFrame($frame);
        if (!is_string($decodedMessage)) {
            self::$logger->error("Servidor: Frame decodificado não é string. Ignorando.");
            return;
        }

        self::$logger->error("Servidor: Mensagem decodificada (string): $decodedMessage");

        $messageData = json_decode($decodedMessage, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$logger->error(
                "Servidor: JSON inválido: " . json_last_error_msg() .
                " | Payload (hex): " . bin2hex($decodedMessage)
            );
            return;
        }

        self::$logger->error("Servidor: messageData após json_decode: " . json_encode($messageData));

        $destination_devices = $clients;

        if (is_array($messageData)) {
            // A mensagem pode ser um array com um único elemento
            $targetMessage = $messageData[0] ?? $messageData;

            if (is_array($targetMessage) && isset($targetMessage['destination'])) {
                $destination = $targetMessage['destination'];
                self::$logger->error("Servidor: Destino especificado: {$destination}");

                $device = $this->deviceService->discoveryDevice($destination);
                self::$logger->error("Servidor: Resultado de discoveryDevice para destino {$destination}: " . ($device ? 'encontrado' : 'não encontrado'));

                if ($device) {
                    $deviceId = $device->getDevice();
                    self::$logger->error("Servidor: ID do dispositivo retornado: {$deviceId}");

                    if (is_string($deviceId) && isset($clients[$deviceId]) && $clients[$deviceId] instanceof ConnectionInterface) {
                        $destination_devices = [$clients[$deviceId]];
                        self::$logger->error("Servidor: Dispositivo encontrado em clients: {$deviceId}");
                    } else {
                        self::$logger->error(
                            "Servidor: Dispositivo com ID {$deviceId} não está conectado ou não é ConnectionInterface. " .
                            "Tipo: " . gettype($clients[$deviceId] ?? 'não existe')
                        );
                        return;
                    }
                } else {
                    self::$logger->error("Servidor: Dispositivo não encontrado para destino: {$destination}");
                    return;
                }
            } else {
                self::$logger->error("Servidor: Nenhum destino especificado ou messageData inválido");
            }
        } else {
            self::$logger->error("Servidor: messageData não é um array: " . gettype($messageData));
        }

        self::$logger->error("Servidor: Enviando para " . count($destination_devices) . " clientes");
        $encodedFrame = $this->encodeWebSocketFrame($decodedMessage);
        $this->sendMessages($sender, $destination_devices, $encodedFrame);
    }

    /**
     * Envia o frame codificado para os clientes
     */
    private function sendMessages(ConnectionInterface $sender, array $clients, string $encodedFrame)
    {
        self::$logger->error('Quantidade de clientes: ' . count($clients));

        if (empty($clients)) {
            self::$logger->error("Servidor: Nenhum cliente disponível para envio");
            return;
        }

        foreach ($clients as $client) {
            if (!$client instanceof ConnectionInterface) {
                self::$logger->error("Servidor: Cliente inválido encontrado: " . json_encode($client));
                continue;
            }

            self::$logger->error('Dados: ' . json_encode(['resourceId' => $client->resourceId ?? 'undefined']));

            if ($client !== $sender) {
                self::$logger->error("Servidor: Enviando mensagem para cliente ID: " . ($client->resourceId ?? 'undefined'));
                try {
                    $client->write($encodedFrame);
                } catch (\Exception $e) {
                    self::$logger->error("Servidor: Falha ao enviar mensagem para cliente: " . $e->getMessage());
                }
            }
        }
    }
}