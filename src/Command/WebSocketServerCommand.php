<?php

namespace ControleOnline\Command;

use ControleOnline\Service\WebsocketServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebSocketServerCommand extends Command
{

    public function __construct(
        private WebsocketServer $websocketServer
    ) {}
    protected function configure()
    {
        $this
            ->setName('websocket:start')
            ->setDescription('Starts the WebSocket server');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $this->websocketServer->init();

        return Command::SUCCESS;
    }
}
