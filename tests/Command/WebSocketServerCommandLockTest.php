<?php

declare(strict_types=1);

namespace ControleOnline\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * Documents the lock contract for websocket:start (api-community#68):
 * acquire failure => SUCCESS + log (idempotent under cron).
 */
final class WebSocketServerCommandLockTest extends TestCase
{
    public function testLockContractIsDocumentedForCronIdempotency(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Command/WebSocketServerCommand.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('if (!$this->lock->acquire())', $source);
        self::assertStringContainsString('WebSocket já em execução', $source);
        self::assertStringContainsString('return Command::SUCCESS', $source);
        self::assertStringContainsString('Iniciando WebSocket server', $source);
    }
}
