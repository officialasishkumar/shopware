<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Outbox;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Webhook\EventLog\WebhookEventLogDefinition;
use Shopware\Core\Framework\Webhook\Message\WebhookEventMessage;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;

/**
 * @internal
 */
#[Package('framework')]
class OutboxEventRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Ensures an outbox entry exists for the given message.
     * Atomically creates the webhook_event_log + webhook_delivery rows if they don't exist.
     */
    public function ensureOutboxEntry(WebhookEventMessage $message): OutboxInsertResult
    {
        $this->connection->beginTransaction();
        $isNew = false;
        $sequence = 0;

        try {
            $eventLogId = Uuid::fromHexToBytes($message->getWebhookEventId());

            $exists = $this->connection->fetchOne(
                'SELECT 1 FROM webhook_event_log WHERE id = :id',
                ['id' => $eventLogId]
            );

            if ($exists === false) {
                $isNew = true;
                $this->insertEventLog($message, $eventLogId);
            }

            $deliveryExists = $this->connection->fetchOne(
                'SELECT 1 FROM webhook_delivery WHERE webhook_event_log_id = :id',
                ['id' => $eventLogId]
            );

            if ($deliveryExists === false) {
                $partitionKey = $message->getPartitionKey();

                $this->connection->executeStatement(
                    'INSERT IGNORE INTO webhook_stream (partition_key, created_at) VALUES (:key, NOW(3))',
                    ['key' => $partitionKey]
                );

                $this->connection->insert('webhook_delivery', [
                    'webhook_event_log_id' => $eventLogId,
                    'webhook_id' => Uuid::fromHexToBytes($message->getWebhookId()),
                    'partition_key' => $partitionKey,
                    'delivery_status' => WebhookEventLogDefinition::STATUS_QUEUED,
                    'created_at' => $this->clock->now()->format('Y-m-d H:i:s.v'),
                ]);

                $sequence = (int) $this->connection->lastInsertId();

                $this->connection->executeStatement(
                    'UPDATE webhook_event_log SET sequence = :sequence WHERE id = :id',
                    ['sequence' => $sequence, 'id' => $eventLogId]
                );
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return new OutboxInsertResult($isNew, $sequence);
    }

    /**
     * Maximum age (seconds) before a RUNNING row is considered stale and re-claimable.
     * Covers connect timeout + request timeout + overhead. A full lease mechanism replaces this in Path 2.
     */
    private const RUNNING_STALENESS_SECONDS = 60;

    /**
     * Transitions delivery to RUNNING and records the attempt.
     * Increments execution_count on the delivery row and sets last_attempt_at on the event log.
     *
     * Returns false when:
     * - The event log row is in a terminal state (SUCCESS, FAILED)
     * - The row is RUNNING and last_attempt_at is recent (another worker is actively processing)
     *
     * Returns true when:
     * - Row is in QUEUED or PENDING_RETRY (normal claim)
     * - Row is RUNNING but last_attempt_at is stale (crash recovery)
     * - Row doesn't exist (resilience for deleted event logs)
     *
     * @return bool true if delivery should proceed, false if blocked
     */
    public function markRunning(string $eventLogId): bool
    {
        return $this->connection->transactional(function () use ($eventLogId): bool {
            $now = $this->clock->now();
            $id = Uuid::fromHexToBytes($eventLogId);

            $row = $this->connection->fetchAssociative(
                'SELECT delivery_status, last_attempt_at FROM webhook_event_log WHERE id = :id',
                ['id' => $id]
            );

            // Row doesn't exist — still allow delivery (resilience for deleted/missing event logs)
            if ($row === false) {
                return true;
            }

            $currentStatus = $row['delivery_status'];

            // Terminal states: delivery already completed — block duplicate delivery
            if (\in_array($currentStatus, [WebhookEventLogDefinition::STATUS_SUCCESS, WebhookEventLogDefinition::STATUS_FAILED], true)) {
                return false;
            }

            // RUNNING: only re-claim if the previous attempt is stale (crash recovery).
            // If last_attempt_at is recent, another worker is likely still processing — block.
            if ($currentStatus === WebhookEventLogDefinition::STATUS_RUNNING && $row['last_attempt_at'] !== null) {
                $elapsed = $now->getTimestamp() - (new \DateTimeImmutable($row['last_attempt_at']))->getTimestamp();

                if ($elapsed < self::RUNNING_STALENESS_SECONDS) {
                    return false;
                }
            }

            // QUEUED, PENDING_RETRY, or stale RUNNING — claim and proceed
            $this->connection->executeStatement(
                'UPDATE webhook_event_log SET delivery_status = :running, last_attempt_at = :now WHERE id = :id',
                [
                    'running' => WebhookEventLogDefinition::STATUS_RUNNING,
                    'now' => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'id' => $id,
                ]
            );

            $this->connection->executeStatement(
                'UPDATE webhook_delivery SET delivery_status = :running, execution_count = execution_count + 1 WHERE webhook_event_log_id = :id',
                [
                    'running' => WebhookEventLogDefinition::STATUS_RUNNING,
                    'id' => $id,
                ]
            );

            return true;
        });
    }

    /**
     * Marks delivery as successful. Updates the event log with response details and removes
     * the delivery row from the hot queue.
     *
     * @param array<string, mixed>|null $requestContent
     * @param array<string, mixed>|null $responseContent
     */
    public function markSuccess(
        string $eventLogId,
        int $processingTime = 0,
        ?array $requestContent = null,
        ?array $responseContent = null,
        ?int $statusCode = null,
        ?string $reasonPhrase = null,
    ): void {
        $this->connection->transactional(function () use ($eventLogId, $processingTime, $requestContent, $responseContent, $statusCode, $reasonPhrase): void {
            $id = Uuid::fromHexToBytes($eventLogId);

            $this->connection->update('webhook_event_log', array_filter([
                'delivery_status' => WebhookEventLogDefinition::STATUS_SUCCESS,
                'processing_time' => $processingTime,
                'request_content' => $requestContent !== null ? json_encode($requestContent, \JSON_THROW_ON_ERROR) : null,
                'response_content' => $responseContent !== null ? json_encode($responseContent, \JSON_THROW_ON_ERROR) : null,
                'response_status_code' => $statusCode,
                'response_reason_phrase' => $reasonPhrase,
            ], static fn ($v) => $v !== null), ['id' => $id]);

            $this->connection->executeStatement(
                'DELETE FROM webhook_delivery WHERE webhook_event_log_id = :id',
                ['id' => $id]
            );
        });
    }

    /**
     * Sets delivery_status to PENDING_RETRY on both event log and delivery row.
     * Does NOT compute retry timing (Symfony messenger owns retry scheduling in Path 1).
     * Does NOT delete the delivery row or increment execution_count (markRunning does that on the next attempt).
     */
    public function markPendingRetry(string $eventLogId): void
    {
        $id = Uuid::fromHexToBytes($eventLogId);

        $this->connection->update('webhook_event_log', [
            'delivery_status' => WebhookEventLogDefinition::STATUS_PENDING_RETRY,
        ], ['id' => $id]);

        $this->connection->executeStatement(
            'UPDATE webhook_delivery SET delivery_status = :status WHERE webhook_event_log_id = :id',
            [
                'status' => WebhookEventLogDefinition::STATUS_PENDING_RETRY,
                'id' => $id,
            ]
        );
    }

    /**
     * Records HTTP response details on the event log without changing delivery_status or touching the delivery row.
     * Called by the handler on failure (before throwing) so diagnostics are available for retries and observability.
     *
     * @deprecated Will be replaced by richer response recording in the outbox receiver (Path 2).
     *
     * @param array<string, mixed>|null $requestContent
     * @param array<string, mixed>|null $responseContent
     */
    public function recordDeliveryResponse(
        string $eventLogId,
        int $processingTime = 0,
        ?array $requestContent = null,
        ?array $responseContent = null,
        ?int $statusCode = null,
        ?string $reasonPhrase = null,
    ): void {
        $id = Uuid::fromHexToBytes($eventLogId);

        $this->connection->update('webhook_event_log', array_filter([
            'processing_time' => $processingTime,
            'request_content' => $requestContent !== null ? json_encode($requestContent, \JSON_THROW_ON_ERROR) : null,
            'response_content' => $responseContent !== null ? json_encode($responseContent, \JSON_THROW_ON_ERROR) : null,
            'response_status_code' => $statusCode,
            'response_reason_phrase' => $reasonPhrase,
        ], static fn ($v) => $v !== null), ['id' => $id]);
    }

    /**
     * Marks delivery as permanently failed. Updates the event log and removes the delivery row.
     */
    public function markFailed(string $eventLogId): void
    {
        $this->connection->transactional(function () use ($eventLogId): void {
            $id = Uuid::fromHexToBytes($eventLogId);

            $this->connection->update('webhook_event_log', [
                'delivery_status' => WebhookEventLogDefinition::STATUS_FAILED,
            ], ['id' => $id]);

            $this->connection->executeStatement(
                'DELETE FROM webhook_delivery WHERE webhook_event_log_id = :id',
                ['id' => $id]
            );
        });
    }

    /**
     * Creates the webhook_event_log row via INSERT...SELECT to populate it with
     * fresh webhook/app data atomically.
     */
    private function insertEventLog(WebhookEventMessage $message, string $eventLogId): void
    {
        $createdAt = $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $webhookId = Uuid::fromHexToBytes($message->getWebhookId());
        $partitionKey = $message->getPartitionKey();

        $affected = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO webhook_event_log (
                    id, app_name, delivery_status, webhook_name, event_name,
                    app_version, url, only_live_version, created_at,
                    serialized_webhook_message, partition_key
                )
                SELECT
                    :id, a.name, :status, w.name, w.event_name,
                    a.version, w.url, w.only_live_version, :createdAt,
                    :serializedMessage, :partitionKey
                FROM webhook w
                LEFT JOIN app a ON (a.id = w.app_id)
                WHERE w.id = :webhookId
            SQL,
            [
                'id' => $eventLogId,
                'status' => WebhookEventLogDefinition::STATUS_QUEUED,
                'createdAt' => $createdAt,
                'serializedMessage' => serialize($message),
                'partitionKey' => $partitionKey,
                'webhookId' => $webhookId,
            ]
        );

        if ($affected === 0) {
            throw new InvalidArgumentException(\sprintf(
                'Unable to create webhook event log entry for webhook "%s".',
                $message->getWebhookId()
            ));
        }
    }
}
