<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Transport;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Message\WebhookEventMessage;
use Shopware\Core\Framework\Webhook\Outbox\OutboxEventRepository;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Webhook Outbox Sender.
 *
 * Always persists to webhook_delivery (Outbox).
 *
 * When $innerSender is provided (Broker mode - SQS/Kafka), the message is
 * dispatched to the broker ONLY on initial send (new outbox entry).
 * Retries are never re-dispatched to the broker — MySQLWebhookReceiver
 * handles them from the outbox directly.
 *
 * When $innerSender is null (MySQL mode), the Outbox IS the queue.
 * MySQLWebhookReceiver polls it directly — no inner dispatch needed.
 *
 * @internal
 */
#[Package('framework')]
class WebhookOutboxSender implements SenderInterface
{
    public function __construct(
        private readonly OutboxEventRepository $repository,
        private readonly ?SenderInterface $innerSender = null,
    ) {
    }

    public function send(Envelope $envelope): Envelope
    {
        if (!$envelope->getMessage() instanceof WebhookEventMessage) {
            throw new InvalidArgumentException('The webhook transport only supports WebhookEventMessage.');
        }

        $result = $this->repository->ensureOutboxEntry($envelope->getMessage());

        if ($this->innerSender !== null && $result->isNew) {
            return $this->innerSender->send($envelope);
        }

        return $envelope;
    }
}
