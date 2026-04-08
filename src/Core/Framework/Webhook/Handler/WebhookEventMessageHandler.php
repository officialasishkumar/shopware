<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Handler;

use GuzzleHttp\Exception\BadResponseException;
use Psr\Clock\ClockInterface;
use Shopware\Core\Framework\App\Exception\AppNotFoundException;
use Shopware\Core\Framework\App\Payload\AppPayloadServiceHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteTypeIntendException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Message\WebhookEventMessage;
use Shopware\Core\Framework\Webhook\Outbox\OutboxEventRepository;
use Shopware\Core\Framework\Webhook\Service\RelatedWebhooks;
use Shopware\Core\Framework\Webhook\Service\WebhookClient;
use Shopware\Core\Framework\Webhook\WebhookException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @internal
 */
#[AsMessageHandler]
#[Package('framework')]
final readonly class WebhookEventMessageHandler
{
    /**
     * @internal
     */
    public function __construct(
        private readonly WebhookClient $webhookClient,
        private readonly AppPayloadServiceHelper $appPayloadServiceHelper,
        private readonly ClockInterface $clock,
        private readonly RelatedWebhooks $relatedWebhooks,
        private readonly OutboxEventRepository $outboxEventRepository,
    ) {
    }

    public function __invoke(WebhookEventMessage $message): void
    {
        $context = Context::createDefaultContext();

        $request = $this->appPayloadServiceHelper->createWebhookRequest(
            $message->getPayload(),
            $message->getUrl(),
            $message->getShopwareVersion(),
            WebhookClient::CONNECT_TIMEOUT,
            WebhookClient::REQUEST_TIMEOUT,
            $message->getSecret(),
            $message->getLanguageId(),
            $message->getUserLocale(),
            $message->getWebhookHeaders(),
        );

        if (!$this->outboxEventRepository->markRunning($message->getWebhookEventId())) {
            // Event log exists in a terminal (SUCCESS/FAILED) or recently-claimed RUNNING state.
            // Skip to prevent duplicate delivery on at-least-once transports (Doctrine, SQS redelivery).
            return;
        }

        // markRunning returns true when:
        // - Row claimed successfully (QUEUED/PENDING_RETRY → RUNNING)
        // - Row doesn't exist (upgrade resilience: messages dispatched before ensureOutboxEntry was
        //   introduced still deliver; outbox state updates become no-ops without audit trail)
        // - Row is RUNNING but stale (crash recovery)

        try {
            $result = $this->webhookClient->send($request);
        } catch (\Throwable $e) {
            // send() wraps TransferException internally and never throws in practice;
            // this guards against unexpected failures so the delivery row is never left in RUNNING state.
            throw WebhookException::webhookFailedException($message->getWebhookId(), $e);
        }

        $processingTime = $this->clock->now()->getTimestamp() - $request->timestamp;

        if ($result->successful()) {
            $this->outboxEventRepository->markSuccess(
                $message->getWebhookEventId(),
                $processingTime,
                ['headers' => $request->headers, 'body' => $request->body],
                ['headers' => $result->headers, 'body' => $result->body],
                $result->statusCode,
                $result->reasonPhrase,
            );

            try {
                $this->relatedWebhooks->updateRelated($message->getWebhookId(), ['error_count' => 0], $context);
            } catch (AppNotFoundException|WriteTypeIntendException) {
            }

            return;
        }

        // Record response details before throwing so diagnostics are available for retries/observability.
        // RetryWebhookMessageFailedSubscriber owns the delivery row state (markPendingRetry / markFailed).
        $this->outboxEventRepository->recordDeliveryResponse(
            $message->getWebhookEventId(),
            $processingTime,
            ['headers' => $request->headers, 'body' => $request->body],
            $result->hasResponse() ? ['headers' => $result->headers, 'body' => $result->body] : null,
            $result->statusCode,
            $result->reasonPhrase,
        );

        $exception = $result->exception;
        if ($exception instanceof BadResponseException && $message->getAppId()) {
            throw WebhookException::appWebhookFailedException($message->getWebhookId(), $message->getAppId(), $exception);
        }

        throw WebhookException::webhookFailedException($message->getWebhookId(), $exception);
    }
}
