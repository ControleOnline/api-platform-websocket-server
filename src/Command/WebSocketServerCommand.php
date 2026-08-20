<?php

namespace ControleOnline\Command;

use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\Server\WebsocketServer;
use ControleOnline\Service\SkyNetService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

class WebSocketServerCommand extends DefaultCommand
{
    protected $input;
    protected $output;
    protected $lock;

    public function __construct(
        LockFactory $lockFactory,
        DatabaseSwitchService $databaseSwitchService,
        LoggerService $loggerService,
        SkyNetService $skyNetService,
        private WebsocketServer $websocketServer,
        private IntegrationService $integrationService,
        private EntityManagerInterface $entityManager,
        private StatusService $statusService,
        private DomainService $domainService,
        private ContainerInterface $container,
    ) {
        $this->skyNetService = $skyNetService;
        $this->lockFactory = $lockFactory;
        $this->databaseSwitchService = $databaseSwitchService;
        $this->loggerService = $loggerService;
        parent::__construct('websocket:start');
    }

    protected function configure()
    {
        $this->addOption('port', ['p'], InputOption::VALUE_OPTIONAL, 'Websocket Port');
        $this->addOption('bind', ['b'], InputOption::VALUE_OPTIONAL, 'Websocket Bind IP');
        $this->setDescription('Starts the WebSocket server');
    }

    protected function runCommand(): int
    {
        $port = $this->input->getOption('port') ?? '8080';
        $bind = $this->input->getOption('bind') ?? '0.0.0.0';

        // Long-running process: if another instance holds the lock, exit cleanly
        // (expected when cron fires every minute while the server is already up).
        if (!$this->lock->acquire()) {
            $this->addLog(sprintf(
                'WebSocket já em execução (lock websocket:start). Ignorando nova instância (bind=%s port=%s).',
                $bind,
                $port
            ));

            return Command::SUCCESS;
        }

        $this->addLog(sprintf('Iniciando WebSocket server em %s:%s', $bind, $port));
        $this->websocketServer->init($bind, $port);

        return Command::SUCCESS;
    }
}
