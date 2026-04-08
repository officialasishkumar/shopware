<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Subscriber;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Message\WebhookEventMessage;
use Shopware\Core\Framework\Webhook\Outbox\OutboxEventRepository;
use Shopware\Core\Framework\Webhook\Service\RelatedWebhooks;
use Shopware\Core\Framework\Webhook\WebhookFailureStrategy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * @codeCoverageIgnore Integration tested with \Shopware\Tests\Integration\Core\Framework\Webhook\Subscriber\RetryWebhookMessageFailedSubscriberTest
 *
 * @internal
 */
#[Package('framework')]
class RetryWebhookMessageFailedSubscriber implements EventSubscriberInterface
{
    private const MAX_WEBHOOK_ERROR_COUNT = 10;

    private readonly WebhookFailureStrategy $failureStrategy;

    /**
     * @internal
     */
    public function __construct(
        private readonly OutboxEventRepository $outboxEventRepository,
        private readonly RelatedWebhooks $relatedWebhooks,
        string $failureStrategy = WebhookFailureStrategy::DisableOnThreshold->value,
    ) {
        $this->failureStrategy = WebhookFailureStrategy::from($failureStrategy);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'failed',
        ];
    }

    public function failed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof WebhookEventMessage) {
            return;
        }

        if ($event->willRetry()) {
            // Transition RUNNING → PENDING_RETRY so the next markRunning() call can claim it.
            // Without this, the delivery row stays in RUNNING and all subsequent retries are blocked.
            $this->outboxEventRepository->markPendingRetry($message->getWebhookEventId());

            return;
        }

        $this->outboxEventRepository->markFailed($message->getWebhookEventId());

        $this->applyFailureStrategy($message->getWebhookId());
    }

    private function applyFailureStrategy(string $webhookId): void
    {
        $webhook = $this->relatedWebhooks->getWebhookState($webhookId);

        if (!\is_array($webhook) || !$webhook['active']) {
            return;
        }

        $context = Context::createDefaultContext();

        $params = match ($this->failureStrategy) {
            WebhookFailureStrategy::DisableOnThreshold => $this->handleDisableOnThreshold($webhook),
            WebhookFailureStrategy::Ignore => $this->handleIgnore($webhook),
        };

        $this->relatedWebhooks->updateRelated($webhookId, $params, $context);
    }

    /**
     * @param array{active: int, error_count: int} $webhook
     *
     * @return array<string, int>
     */
    private function handleDisableOnThreshold(array $webhook): array
    {
        $errorCount = $webhook['error_count'] + 1;

        if ($errorCount >= self::MAX_WEBHOOK_ERROR_COUNT) {
            return ['error_count' => 0, 'active' => 0];
        }

        return ['error_count' => $errorCount];
    }

    /**
     * @param array{active: int, error_count: int} $webhook
     *
     * @return array<string, int>
     */
    private function handleIgnore(array $webhook): array
    {
        return ['error_count' => $webhook['error_count'] + 1];
    }
}
