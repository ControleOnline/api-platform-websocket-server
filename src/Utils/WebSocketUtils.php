<?php

namespace ControleOnline\Utils;

trait WebSocketUtils
{
    protected static $logger;

    public static function parseHeaders(string $buffer): array
    {
        $headers = [];
        $headerLines = explode("\r\n", substr($buffer, 0, strpos($buffer, "\r\n\r\n")));

        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim(strtolower($key))] = trim($value);
            }
        }

        return $headers;
    }

    public static function generateHandshakeResponse(array $headers): ?string
    {
        if (
            !isset($headers['upgrade']) || strtolower($headers['upgrade']) !== 'websocket' ||
            !isset($headers['connection']) || stripos($headers['connection'], 'upgrade') === false ||
            !isset($headers['sec-websocket-key']) ||
            !isset($headers['sec-websocket-version']) || $headers['sec-websocket-version'] !== '13'
        ) {
            return null;
        }

        $secWebSocketKey = $headers['sec-websocket-key'];
        $magicString = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
        $hash = base64_encode(sha1($secWebSocketKey . $magicString, true));

        return "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: $hash\r\n\r\n";
    }

    public static function encodeWebSocketFrame(string $payload, int $opcode = 0x1): string
    {
        $frameHead = [];
        $payloadLength = strlen($payload);

        $frameHead[0] = 0x80 | $opcode;

        if ($payloadLength > 65535) {
            $frameHead[1] = 127;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[2 + $i] = ($payloadLength >> (56 - 8 * $i)) & 0xFF;
            }
        } elseif ($payloadLength > 125) {
            $frameHead[1] = 126;
            $frameHead[2] = ($payloadLength >> 8) & 0xFF;
            $frameHead[3] = $payloadLength & 0xFF;
        } else {
            $frameHead[1] = $payloadLength;
        }

        return pack('C*', ...$frameHead) . $payload;
    }

    public static function decodeWebSocketFrame(string $data): ?array
    {
        if (strlen($data) < 2) return null;

        $payloadOffset = 2;
        $masked = (ord($data[1]) >> 7) & 0x1;
        $payloadLength = ord($data[1]) & 0x7F;

        if ($payloadLength === 126) {
            $payloadLength = unpack('n', substr($data, 2, 2))[1];
            $payloadOffset = 4;
        } elseif ($payloadLength === 127) {
            $payloadLength = unpack('J', substr($data, 2, 8))[1] ?? 0;
            $payloadOffset = 10;
        }

        $payload = '';
        if ($masked) {
            $maskingKey = substr($data, $payloadOffset, 4);
            $payloadOffset += 4;
            for ($i = 0; $i < $payloadLength; $i++) {
                $payload .= $data[$payloadOffset + $i] ^ $maskingKey[$i % 4];
            }
        } else {
            $payload = substr($data, $payloadOffset, $payloadLength);
        }

        return [
            'opcode' => ord($data[0]) & 0x0F,
            'payload' => $payload
        ];
    }
}
