<?php

namespace ControleOnline\Message\Websocket;

class PushMessage
{
    public function __construct(
        public readonly string $deviceId,
        public readonly string $message
    ) {}
}
