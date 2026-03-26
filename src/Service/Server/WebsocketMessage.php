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

    public function sendMessage(ConnectionInterface $sender, array $clients, string $frame): void
    {
        self::$logger->error("Servidor: Recebido frame para broadcast (hex): " . bin2hex($frame));

        // Decodifica o frame
        $decodedMessage = $this->decodeWebSocketFrame($frame);
        if ($decodedMessage === null) {
            self::$logger->error("Servidor: Falha ao decodificar frame WebSocket");
            return;
        }
        self::$logger->error("Servidor: Mensagem decodificada (string): $decodedMessage");

        // Tenta decodificar JSON
        $messageData = json_decode($decodedMessage, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$logger->error("Servidor: Payload não é JSON válido: " . bin2hex($decodedMessage));
            return;
        }

        if (!is_array($messageData)) {
            self::$logger->error("Servidor: messageData não é um array: " . gettype($messageData));
            return;
        }

        self::$logger->error("Servidor: messageData após json_decode: " . json_encode($messageData));

        // Lista inicial de dispositivos destino
        $destination_devices = $clients;

        // Verifica se existe destino especificado
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
                    self::$logger->error("Servidor: Dispositivo com ID {$deviceId} não está conectado ou não é ConnectionInterface. Tipo: " . gettype($clients[$deviceId] ?? 'não existe'));
                    return;
                }
            } else {
                self::$logger->error("Servidor: Dispositivo não encontrado para destino: {$destination}");
                return;
            }
        } else {
            self::$logger->error("Servidor: Nenhum destino especificado ou messageData inválido");
        }

        // Envia para os clientes selecionados
        self::$logger->error("Servidor: Enviando para " . count($destination_devices) . " clientes");
        $encodedFrame = $this->encodeWebSocketFrame($decodedMessage);
        $this->sendMessages($sender, $destination_devices, $encodedFrame);
    }

    private function sendMessages(ConnectionInterface $sender, array $clients, string $encodedFrame): void
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
                $client->write($encodedFrame);
            }
        }
    }
}
