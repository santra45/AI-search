<?php
namespace Czar\SemanticSearch\Console\Command;

use Czar\SemanticSearch\Model\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCatalogCommand extends Command
{
    public function __construct(private SyncService $syncService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('semantic:sync:catalog')
            ->setDescription('Sync Magento catalog products to Semantic Search FastAPI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Starting semantic sync...</info>');
        $result = $this->syncService->syncAll();
        $output->writeln('<comment>' . json_encode($result, JSON_PRETTY_PRINT) . '</comment>');

        return Command::SUCCESS;
    }
}
