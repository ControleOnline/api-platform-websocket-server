<?php

namespace ControleOnline\Command;

use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\Server\WebsocketServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebSocketServerCommand extends Command
{

    public function __construct(
        private WebsocketServer $websocketServer,
        private DatabaseSwitchService $databaseSwitchService,
        private DomainService $domainService
    ) {
        $databaseSwitchService->switchDatabaseByDomain('api.controleonline.com');
        parent::__construct();
    }
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
