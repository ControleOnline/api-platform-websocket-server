<?php

namespace ControleOnline\Service;

use ControleOnline\Utils\WebSocketUtils;
use React\Socket\ConnectionInterface;

class WebsocketMessage
{
    use WebSocketUtils;

    public function __construct(
        private DeviceService $deviceService
    ) {}

    public function sendMessage(ConnectionInterface $sender, array $clients, string $frame): void
    {
        error_log("Servidor: Recebido frame para broadcast (hex): " . bin2hex($frame));
        $decodedMessage = $this->decodeWebSocketFrame($frame);
        if ($decodedMessage === null) {
            error_log("Servidor: Falha ao decodificar frame WebSocket");
            return;
        }
        error_log("Servidor: Mensagem decodificada (string): $decodedMessage");

        $messageData = json_decode($decodedMessage, true);
        error_log("Servidor: messageData após json_decode: " . json_encode($messageData));
        $destination_devices = $clients;

        if (is_array($messageData)) {
            // A mensagem pode ser um array com um único elemento, então acessamos o primeiro item
            $targetMessage = $messageData[0] ?? null;
            if (is_array($targetMessage) && isset($targetMessage['destination'])) {
                $destination = $targetMessage['destination'];
                error_log("Servidor: Destino especificado: {$destination}");
                $device = $this->deviceService->discoveryDevice($destination);
                error_log("Servidor: Resultado de discoveryDevice para destino {$destination}: " . ($device ? 'encontrado' : 'não encontrado'));
                if ($device) {
                    $deviceId = $device->getDevice();
                    error_log("Servidor: ID do dispositivo retornado: {$deviceId}");
                    if (is_string($deviceId) && isset($clients[$deviceId]) && $clients[$deviceId] instanceof ConnectionInterface) {
                        $destination_devices = [$clients[$deviceId]];
                        error_log("Servidor: Dispositivo encontrado em clients: {$deviceId}");
                    } else {
                        error_log("Servidor: Dispositivo com ID {$deviceId} não está conectado ou não é ConnectionInterface. Tipo: " . gettype($clients[$deviceId] ?? 'não existe'));
                        return;
                    }
                } else {
                    error_log("Servidor: Dispositivo não encontrado para destino: {$destination}");
                    return;
                }
            } else {
                error_log("Servidor: Nenhum destino especificado ou messageData inválido");
            }
        } else {
            error_log("Servidor: messageData não é um array: " . gettype($messageData));
        }

        error_log("Servidor: Enviando para " . count($destination_devices) . " clientes");
        $encodedFrame = $this->encodeWebSocketFrame($decodedMessage);
        $this->sendMessages($sender, $destination_devices, $encodedFrame);
    }

    private function sendMessages(ConnectionInterface $sender, array $clients, string $encodedFrame)
    {
        error_log('Quantidade de clientes: ' . count($clients));

        if (empty($clients)) {
            error_log("Servidor: Nenhum cliente disponível para envio");
            return;
        }

        foreach ($clients as $client) {
            if (!$client instanceof ConnectionInterface) {
                error_log("Servidor: Cliente inválido encontrado: " . json_encode($client));
                continue;
            }
            error_log('Dados: ' . json_encode(['resourceId' => $client->resourceId ?? 'undefined']));
            if ($client !== $sender) {
                error_log("Servidor: Enviando mensagem para cliente ID: " . ($client->resourceId ?? 'undefined'));
                $client->write($encodedFrame);
            }
        }
    }
}