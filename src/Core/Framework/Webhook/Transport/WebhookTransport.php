<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Transport;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @internal
 *
 * Dedicated Messenger transport for webhook delivery.
 *
 * Send path: delegates to WebhookOutboxSender which persists to the outbox.
 * Receive path: will be wired to MySQLWebhookReceiver in Path 2. Currently returns empty.
 */
#[Package('framework')]
class WebhookTransport implements TransportInterface
{
    public function __construct(
        private readonly WebhookOutboxSender $sender,
    ) {
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->sender->send($envelope);
    }

    /**
     * @return list<Envelope>
     */
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }
}
