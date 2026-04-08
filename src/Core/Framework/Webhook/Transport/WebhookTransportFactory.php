<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Transport;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Outbox\OutboxEventRepository;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @internal
 */
#[Package('framework')]
class WebhookTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly TransportFactory $transportFactory,
        private readonly string $defaultTransportDsn,
        private readonly OutboxEventRepository $outboxEventRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $innerDsn = $this->resolveInnerDsn();

        if ($this->isSqsDsn($innerDsn)) {
            $brokerTransport = $this->transportFactory->createTransport($innerDsn, $options, $serializer);

            return new WebhookTransport(
                new WebhookOutboxSender($this->outboxEventRepository, $brokerTransport)
            );
        }

        // MySQL Stream leasing (default): outbox IS the queue, no inner dispatch needed.
        return new WebhookTransport(
            new WebhookOutboxSender($this->outboxEventRepository)
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'shopware-webhook://');
    }

    private function resolveInnerDsn(): string
    {
        if (str_starts_with($this->defaultTransportDsn, 'shopware-webhook://')) {
            throw new InvalidArgumentException('The webhook transport cannot wrap itself.');
        }

        return $this->defaultTransportDsn;
    }

    private function isSqsDsn(string $dsn): bool
    {
        return str_contains($dsn, 'sqs')
            || str_starts_with($dsn, 'https://sqs.');
    }
}
