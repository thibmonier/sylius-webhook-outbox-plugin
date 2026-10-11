<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Form\Type;

use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeRegistryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Validator\EndpointUrl;

/**
 * The secret is not a field: generated on creation, regenerated on demand, shown once.
 */
final class WebhookEndpointType extends AbstractResourceType
{
    /**
     * @param list<string> $validationGroups
     * @param RepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        string $dataClass,
        array $validationGroups,
        private readonly EventTypeRegistryInterface $eventTypes,
        private readonly RepositoryInterface $channelRepository,
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $eventTypes = [];
        foreach ($this->eventTypes->subscribable() as $type) {
            $eventTypes[$type->getLabel()] = $type->type;
        }

        $channels = [];
        foreach ($this->channelRepository->findAll() as $channel) {
            $channels[(string) $channel->getName()] = (string) $channel->getCode();
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'sylius.ui.name',
                'constraints' => [new NotBlank(groups: ['sylius']), new Length(max: 255, groups: ['sylius'])],
            ])
            ->add('url', TextType::class, [
                'label' => 'tmonier_webhook.ui.url',
                'help' => 'tmonier_webhook.ui.url_help',
                'constraints' => [new NotBlank(groups: ['sylius']), new Length(max: 2048, groups: ['sylius']), new EndpointUrl(groups: ['sylius'])],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'sylius.ui.description',
                'required' => false,
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'tmonier_webhook.ui.enabled',
                'required' => false,
            ])
            ->add('eventTypes', ChoiceType::class, [
                'label' => 'tmonier_webhook.ui.event_types',
                'choices' => $eventTypes,
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Count(min: 1, minMessage: 'tmonier_webhook.endpoint.event_types.min', groups: ['sylius'])],
            ])
            ->add('channelCodes', ChoiceType::class, [
                'label' => 'sylius.ui.channels',
                'help' => 'tmonier_webhook.ui.channels_help',
                'choices' => $channels,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_webhook_endpoint';
    }
}
