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
        $destination_devices = $clients;

        if (is_array($messageData) && isset($messageData['destination'])) {
            $device = $this->deviceService->discoveryDevice($messageData['destination']);
            error_log("Servidor: Resultado de discoveryDevice para destino {$messageData['destination']}: " . ($device ? 'encontrado' : 'não encontrado'));
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
                error_log("Servidor: Dispositivo não encontrado para destino: " . $messageData['destination']);
                return;
            }
        } else {
            error_log("Servidor: Enviando para todos os clientes. Total de clientes: " . count($clients));
        }

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
