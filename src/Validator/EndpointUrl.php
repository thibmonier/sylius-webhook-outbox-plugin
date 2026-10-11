<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class EndpointUrl extends Constraint
{
    public function validatedBy(): string
    {
        return 'tmonier_webhook_endpoint_url';
    }
}
