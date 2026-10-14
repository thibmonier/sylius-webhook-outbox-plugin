<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookDispatcher;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;

/**
 * The worker. Without --loop it sends what is due and exits (cron); with --loop it polls until
 * --time-limit, --limit or SIGTERM / SIGINT (systemd, supervisor). Several workers may run: the
 * claim uses SKIP LOCKED and a lease.
 */
#[AsCommand(name: 'tmonier:webhook:dispatch', description: 'Send the due webhook deliveries (retries included)')]
final class DispatchWebhooksCommand extends Command implements SignalableCommandInterface
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly float $maxDuration = 30,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Keep polling for due deliveries instead of exiting when none is left')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this number of seconds (0: no limit)', '0')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this number of deliveries (0: no limit)', '0')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when nothing is due (--loop)', '1')
            ->setHelp(<<<'HELP'
                Cron, every minute: requests start during the first --time-limit minus max_duration
                seconds (25 s with the defaults), and flock skips a run while the previous one is
                still busy (DNS resolution is not bounded by max_duration):
                  <info>* * * * * flock -n /tmp/sylius-webhooks.lock php bin/console tmonier:webhook:dispatch --time-limit=55</info>

                Long-running worker (systemd / supervisor restart it after --time-limit):
                  <info>php bin/console tmonier:webhook:dispatch --loop --time-limit=3600</info>
                HELP)
        ;
    }

    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // Finish the delivery in progress, then exit cleanly.
        $this->shouldStop = true;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $loop = (bool) $input->getOption('loop');
        $timeLimit = $this->positiveInt($input->getOption('time-limit'));
        $limit = $this->positiveInt($input->getOption('limit'));
        $sleep = max(0.1, (float) $this->numeric($input->getOption('sleep')));
        if ($timeLimit > 0 && $timeLimit <= $this->maxDuration) {
            $output->writeln(sprintf('<error>--time-limit must be greater than max_duration (%s s): no request could start.</error>', $this->maxDuration));

            return Command::INVALID;
        }
        $deadline = $timeLimit > 0 ? microtime(true) + $timeLimit : null;

        $sent = 0;
        $counts = array_fill_keys(WebhookDeliveryInterface::STATUSES, 0);

        while (!$this->shouldStop) {
            // Only start a request that max_duration lets finish before the deadline.
            if (null !== $deadline && microtime(true) + $this->maxDuration >= $deadline) {
                break;
            }
            if ($limit > 0 && $sent >= $limit) {
                break;
            }

            try {
                $delivery = $this->dispatcher->dispatchNext();
            } catch (\Throwable $exception) {
                // Database hiccup: log, keep the worker alive, back off.
                // The dispatcher released the claim and reset a closed entity manager.
                $this->logger->error('Webhook worker error: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
                if (!$loop) {
                    return Command::FAILURE;
                }
                usleep((int) ($sleep * 1_000_000));

                continue;
            }

            if (!$delivery instanceof \Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface) {
                if (!$loop) {
                    break;
                }
                usleep((int) ($sleep * 1_000_000));

                continue;
            }

            ++$sent;
            ++$counts[$delivery->getStatus()];
            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    '#%d %s %s -> endpoint #%d: %s (HTTP %s)',
                    (int) $delivery->getId(),
                    $delivery->getEvent()->getType(),
                    $delivery->getEvent()->getId(),
                    (int) $delivery->getEndpoint()->getId(),
                    $delivery->getStatus(),
                    $delivery->getLastResponseStatus() ?? '-',
                ));
            }
            // Long-running process: do not keep every delivery in memory.
            $this->dispatcher->recover();
        }

        $output->writeln(sprintf(
            '%d attempt(s): %d delivered, %d to retry, %d dead.',
            $sent,
            $counts[WebhookDeliveryInterface::STATUS_DELIVERED],
            $counts[WebhookDeliveryInterface::STATUS_FAILED],
            $counts[WebhookDeliveryInterface::STATUS_DEAD],
        ));

        return Command::SUCCESS;
    }

    private function positiveInt(mixed $value): int
    {
        return max(0, (int) $this->numeric($value));
    }

    private function numeric(mixed $value): float|int
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('Expected a number, "%s" given.', is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return $value + 0;
    }
}
