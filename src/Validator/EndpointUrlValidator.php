<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Tmonier\SyliusWebhookOutboxPlugin\Http\EndpointUrlPolicy;

/**
 * Static rules only (scheme, credentials). Private addresses are refused when sending, after
 * DNS resolution: checking them here would be bypassable (DNS rebinding).
 */
final class EndpointUrlValidator extends ConstraintValidator
{
    public function __construct(private readonly EndpointUrlPolicy $policy)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EndpointUrl) {
            throw new UnexpectedTypeException($constraint, EndpointUrl::class);
        }
        if (null === $value || '' === $value) {
            return;
        }

        $violation = $this->policy->violation(is_string($value) ? $value : null);
        if (null !== $violation) {
            $this->context->buildViolation($violation)->setTranslationDomain('validators')->addViolation();
        }
    }
}
