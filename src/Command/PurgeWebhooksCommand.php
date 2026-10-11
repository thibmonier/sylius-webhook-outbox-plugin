<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\OutboxEventRepositoryInterface;

/**
 * Deletes old events with their deliveries. Events with a delivery still pending or failed
 * (retry scheduled) are kept.
 */
#[AsCommand(name: 'tmonier:webhook:purge', description: 'Delete the webhook events (and deliveries) older than the retention')]
final class PurgeWebhooksCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly OutboxEventRepositoryInterface $eventRepository,
        private readonly ClockInterface $clock,
        private readonly ?int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete events older than this number of days (default: retention_days)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the events that would be deleted')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        if (null === $days) {
            if (null === $this->retentionDays) {
                $output->writeln('retention_days is null: nothing to purge. Use --days to purge anyway.');

                return Command::SUCCESS;
            }
            $days = $this->retentionDays;
        }
        if (!is_numeric($days) || (int) $days < 1) {
            $output->writeln('<error>--days must be a positive number of days.</error>');

            return Command::INVALID;
        }

        $before = $this->clock->now()->modify(sprintf('-%d days', (int) $days));
        if ((bool) $input->getOption('dry-run')) {
            $output->writeln(sprintf('%d event(s) would be deleted.', $this->eventRepository->countPurgeableBefore($before)));

            return Command::SUCCESS;
        }

        $total = 0;
        do {
            $deleted = $this->eventRepository->purgeBefore($before, self::BATCH_SIZE);
            $total += $deleted;
        } while ($deleted > 0);

        $output->writeln(sprintf('%d event(s) deleted with their deliveries.', $total));

        return Command::SUCCESS;
    }
}
