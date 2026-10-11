<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Command;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Maintenance\CustomerWebhookDataAnonymizer;

#[AsCommand(name: 'tmonier:webhook:anonymize-customer', description: 'Redact the personal data of a customer in the webhook events and responses')]
final class AnonymizeCustomerWebhooksCommand extends Command
{
    /** @param RepositoryInterface<CustomerInterface> $customerRepository */
    public function __construct(
        private readonly CustomerWebhookDataAnonymizer $anonymizer,
        private readonly RepositoryInterface $customerRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('customer', InputArgument::REQUIRED, 'Customer id or e-mail (the id also works for deleted customers)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customer = $input->getArgument('customer');
        \assert(is_string($customer));

        if (ctype_digit($customer)) {
            $customerId = (int) $customer;
        } else {
            $found = $this->customerRepository->findOneBy(['emailCanonical' => mb_strtolower($customer)]);
            if (!$found instanceof CustomerInterface || !is_int($found->getId())) {
                $output->writeln('<error>No customer with this e-mail. Pass the customer id instead.</error>');

                return Command::FAILURE;
            }
            $customerId = $found->getId();
        }

        $output->writeln(sprintf('%d webhook event(s) anonymized for customer #%d.', $this->anonymizer->anonymize($customerId), $customerId));

        return Command::SUCCESS;
    }
}
