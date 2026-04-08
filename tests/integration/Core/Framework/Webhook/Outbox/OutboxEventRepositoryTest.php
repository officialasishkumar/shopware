<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Webhook\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Webhook\Message\WebhookEventMessage;
use Shopware\Core\Framework\Webhook\Outbox\OutboxEventRepository;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

/**
 * @internal
 */
class OutboxEventRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour;

    private Connection $connection;

    private OutboxEventRepository $repository;

    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->connection = static::getContainer()->get(Connection::class);
        $this->repository = static::getContainer()->get(OutboxEventRepository::class);
        $this->ids = new IdsCollection();
    }

    public function testEnsureOutboxEntryCreatesEventLogAndDeliveryRow(): void
    {
        $this->createWebhook('wh-1');

        $message = $this->createMessage('evt-1', 'wh-1');
        $result = $this->repository->ensureOutboxEntry($message);

        static::assertTrue($result->isNew);
        static::assertGreaterThan(0, $result->sequence);

        // Verify event log was created
        $eventLog = $this->connection->fetchAssociative(
            'SELECT * FROM `webhook_event_log` WHERE `id` = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );

        static::assertNotFalse($eventLog);
        static::assertSame('queued', $eventLog['delivery_status']);
        static::assertSame($result->sequence, (int) $eventLog['sequence']);
        static::assertSame($message->getPartitionKey(), $eventLog['partition_key']);

        // Verify delivery row was created
        $delivery = $this->connection->fetchAssociative(
            'SELECT * FROM `webhook_delivery` WHERE `id` = :id',
            ['id' => $result->sequence]
        );

        static::assertNotFalse($delivery);
        static::assertSame('queued', $delivery['delivery_status']);
        static::assertSame(0, (int) $delivery['execution_count']);
        static::assertSame($this->ids->getBytes('evt-1'), $delivery['webhook_event_log_id']);
    }

    public function testEnsureOutboxEntryCreatesStreamRow(): void
    {
        $this->createWebhook('wh-1');

        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        $streamRow = $this->connection->fetchAssociative(
            'SELECT * FROM `webhook_stream` WHERE `partition_key` = :pk',
            ['pk' => $message->getPartitionKey()]
        );

        static::assertNotFalse($streamRow);
    }

    public function testEnsureOutboxEntryIsIdempotent(): void
    {
        $this->createWebhook('wh-1');

        $message = $this->createMessage('evt-1', 'wh-1');

        $first = $this->repository->ensureOutboxEntry($message);
        $second = $this->repository->ensureOutboxEntry($message);

        static::assertTrue($first->isNew);
        static::assertFalse($second->isNew);

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `webhook_delivery` WHERE `webhook_event_log_id` = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );

        static::assertSame(1, $count);
    }

    public function testAppWebhookPartitionKeyUsesAppName(): void
    {
        $appId = $this->createApp('MyTestApp');
        $this->createWebhook('wh-1', $appId);

        $message = $this->createMessage('evt-1', 'wh-1', $appId, 'MyTestApp');
        $this->repository->ensureOutboxEntry($message);

        $partitionKey = $this->connection->fetchOne(
            'SELECT `partition_key` FROM `webhook_delivery` WHERE `webhook_event_log_id` = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );

        $expected = hash('xxh128', 'MyTestApp', binary: true);

        static::assertSame($expected, $partitionKey);
    }

    public function testNonAppWebhookPartitionKey(): void
    {
        $this->createWebhook('wh-1');

        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        $partitionKey = $this->connection->fetchOne(
            'SELECT `partition_key` FROM `webhook_delivery` WHERE `webhook_event_log_id` = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );

        $expected = hash('xxh128', '', binary: true);

        static::assertSame($expected, $partitionKey);
    }

    public function testEventLogPopulatedFromWebhookTable(): void
    {
        $appId = $this->createApp('AuditApp');
        $this->createWebhook('wh-1', $appId);

        $message = $this->createMessage('evt-1', 'wh-1', $appId, 'AuditApp');
        $this->repository->ensureOutboxEntry($message);

        $eventLog = $this->connection->fetchAssociative(
            'SELECT * FROM `webhook_event_log` WHERE `id` = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );

        static::assertNotFalse($eventLog);
        static::assertSame('AuditApp', $eventLog['app_name']);
        static::assertSame('test-hook', $eventLog['webhook_name']);
        static::assertSame('product.written', $eventLog['event_name']);
        static::assertSame('https://example.com/webhook', $eventLog['url']);
    }

    public function testDeliveryRowPopulatesWebhookId(): void
    {
        $this->createWebhook('wh-1');

        $message = $this->createMessage('evt-1', 'wh-1');
        $result = $this->repository->ensureOutboxEntry($message);

        $delivery = $this->connection->fetchAssociative(
            'SELECT * FROM `webhook_delivery` WHERE `id` = :id',
            ['id' => $result->sequence]
        );

        static::assertNotFalse($delivery);
        static::assertSame($this->ids->getBytes('wh-1'), $delivery['webhook_id']);
    }

    public function testMarkRunningClaimsQueuedRow(): void
    {
        $this->createWebhook('wh-1');

        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        $claimed = $this->repository->markRunning($this->ids->get('evt-1'));

        static::assertTrue($claimed);

        $status = $this->connection->fetchOne(
            'SELECT delivery_status FROM webhook_event_log WHERE id = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );
        static::assertSame('running', $status);

        $delivery = $this->connection->fetchAssociative(
            'SELECT delivery_status, execution_count FROM webhook_delivery WHERE webhook_event_log_id = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );
        static::assertNotFalse($delivery);
        static::assertSame('running', $delivery['delivery_status']);
        static::assertSame(1, (int) $delivery['execution_count']);
    }

    public function testMarkRunningBlocksTerminalSuccess(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        $this->repository->markRunning($this->ids->get('evt-1'));
        $this->repository->markSuccess($this->ids->get('evt-1'));

        static::assertFalse($this->repository->markRunning($this->ids->get('evt-1')));
    }

    public function testMarkRunningBlocksTerminalFailed(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        $this->repository->markRunning($this->ids->get('evt-1'));
        $this->repository->markFailed($this->ids->get('evt-1'));

        static::assertFalse($this->repository->markRunning($this->ids->get('evt-1')));
    }

    public function testMarkRunningBlocksRecentRunningRow(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        // First claim — sets last_attempt_at to now
        static::assertTrue($this->repository->markRunning($this->ids->get('evt-1')));

        // Immediate re-claim — last_attempt_at is fresh, another worker is likely processing
        static::assertFalse($this->repository->markRunning($this->ids->get('evt-1')));
    }

    public function testMarkRunningReclaimsStaleRunningRow(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        static::assertTrue($this->repository->markRunning($this->ids->get('evt-1')));

        // Simulate crash: backdate last_attempt_at to 2 minutes ago (beyond staleness threshold)
        $staleTime = (new \DateTimeImmutable())->modify('-120 seconds')->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->connection->executeStatement(
            'UPDATE webhook_event_log SET last_attempt_at = :stale WHERE id = :id',
            ['stale' => $staleTime, 'id' => $this->ids->getBytes('evt-1')]
        );

        // Re-claim should succeed — the previous attempt is stale
        static::assertTrue($this->repository->markRunning($this->ids->get('evt-1')));

        $delivery = $this->connection->fetchAssociative(
            'SELECT execution_count FROM webhook_delivery WHERE webhook_event_log_id = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );
        static::assertSame(2, (int) $delivery['execution_count']);
    }

    public function testMarkRunningReturnsTrueForMissingRow(): void
    {
        static::assertTrue($this->repository->markRunning(Uuid::randomHex()));
    }

    public function testMarkRunningClaimsPendingRetryRow(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        $this->repository->markRunning($this->ids->get('evt-1'));
        $this->repository->markPendingRetry($this->ids->get('evt-1'));

        $claimed = $this->repository->markRunning($this->ids->get('evt-1'));
        static::assertTrue($claimed);

        $delivery = $this->connection->fetchAssociative(
            'SELECT delivery_status, execution_count FROM webhook_delivery WHERE webhook_event_log_id = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );
        static::assertSame('running', $delivery['delivery_status']);
        static::assertSame(2, (int) $delivery['execution_count']);
    }

    public function testFullRetryCycle(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        // Attempt 1: QUEUED → RUNNING → fail → PENDING_RETRY
        static::assertTrue($this->repository->markRunning($this->ids->get('evt-1')));
        $this->repository->markPendingRetry($this->ids->get('evt-1'));

        $this->assertEventLogStatus('evt-1', 'pending_retry');
        $this->assertDeliveryExists('evt-1');

        // Attempt 2: PENDING_RETRY → RUNNING → success → deleted
        static::assertTrue($this->repository->markRunning($this->ids->get('evt-1')));
        $this->repository->markSuccess($this->ids->get('evt-1'));

        $this->assertEventLogStatus('evt-1', 'success');
        $this->assertDeliveryDeleted('evt-1');
    }

    public function testMarkSuccessDeletesDeliveryRow(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        $this->repository->markRunning($this->ids->get('evt-1'));
        $this->repository->markSuccess($this->ids->get('evt-1'), 42, ['headers' => []], ['body' => 'ok'], 200, 'OK');

        $this->assertEventLogStatus('evt-1', 'success');
        $this->assertDeliveryDeleted('evt-1');

        $eventLog = $this->connection->fetchAssociative(
            'SELECT processing_time, response_status_code FROM webhook_event_log WHERE id = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );
        static::assertSame(42, (int) $eventLog['processing_time']);
        static::assertSame(200, (int) $eventLog['response_status_code']);
    }

    public function testMarkFailedDeletesDeliveryRow(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        $this->repository->markRunning($this->ids->get('evt-1'));
        $this->repository->markFailed($this->ids->get('evt-1'));

        $this->assertEventLogStatus('evt-1', 'failed');
        $this->assertDeliveryDeleted('evt-1');
    }

    public function testMarkPendingRetryKeepsDeliveryRow(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        $this->repository->markRunning($this->ids->get('evt-1'));
        $this->repository->markPendingRetry($this->ids->get('evt-1'));

        $this->assertEventLogStatus('evt-1', 'pending_retry');
        $this->assertDeliveryExists('evt-1');

        $delivery = $this->connection->fetchAssociative(
            'SELECT delivery_status FROM webhook_delivery WHERE webhook_event_log_id = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );
        static::assertSame('pending_retry', $delivery['delivery_status']);
    }

    public function testExecutionCountIncrementsAcrossRetries(): void
    {
        $this->createWebhook('wh-1');
        $message = $this->createMessage('evt-1', 'wh-1');
        $this->repository->ensureOutboxEntry($message);

        // Three attempts
        $this->repository->markRunning($this->ids->get('evt-1'));
        $this->repository->markPendingRetry($this->ids->get('evt-1'));
        $this->repository->markRunning($this->ids->get('evt-1'));
        $this->repository->markPendingRetry($this->ids->get('evt-1'));
        $this->repository->markRunning($this->ids->get('evt-1'));

        $count = (int) $this->connection->fetchOne(
            'SELECT execution_count FROM webhook_delivery WHERE webhook_event_log_id = :id',
            ['id' => $this->ids->getBytes('evt-1')]
        );
        static::assertSame(3, $count);
    }

    private function assertEventLogStatus(string $eventKey, string $expectedStatus): void
    {
        $status = $this->connection->fetchOne(
            'SELECT delivery_status FROM webhook_event_log WHERE id = :id',
            ['id' => $this->ids->getBytes($eventKey)]
        );
        static::assertSame($expectedStatus, $status);
    }

    private function assertDeliveryExists(string $eventKey): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM webhook_delivery WHERE webhook_event_log_id = :id',
            ['id' => $this->ids->getBytes($eventKey)]
        );
        static::assertNotFalse($exists, 'Expected delivery row to exist');
    }

    private function assertDeliveryDeleted(string $eventKey): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM webhook_delivery WHERE webhook_event_log_id = :id',
            ['id' => $this->ids->getBytes($eventKey)]
        );
        static::assertFalse($exists, 'Expected delivery row to be deleted');
    }

    private function createApp(string $name = 'TestApp'): string
    {
        $appId = Uuid::randomHex();
        $appRepository = static::getContainer()->get('app.repository');
        $appRepository->create([[
            'id' => $appId,
            'name' => $name,
            'active' => true,
            'path' => __DIR__,
            'version' => '1.0.0',
            'label' => 'test',
            'integration' => [
                'label' => 'test',
                'accessKey' => 'test-' . $appId,
                'secretAccessKey' => 'test',
            ],
            'aclRole' => [
                'name' => 'test-role-' . $appId,
            ],
        ]], Context::createDefaultContext());

        return $appId;
    }

    private function createWebhook(string $webhookKey, ?string $appId = null): void
    {
        $this->connection->insert('webhook', [
            'id' => $this->ids->getBytes($webhookKey),
            'name' => 'test-hook',
            'event_name' => 'product.written',
            'url' => 'https://example.com/webhook',
            'app_id' => $appId !== null ? Uuid::fromHexToBytes($appId) : null,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    private function createMessage(
        string $eventKey,
        string $webhookKey,
        ?string $appId = null,
        ?string $appName = null,
    ): WebhookEventMessage {
        return new WebhookEventMessage(
            $this->ids->get($eventKey),
            ['body' => 'payload'],
            $appId,
            $this->ids->get($webhookKey),
            '6.7.0',
            'https://example.com/webhook',
            'test-secret',
            Defaults::LANGUAGE_SYSTEM,
            'en-GB',
            [],
            $appName,
        );
    }
}
