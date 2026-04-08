<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Webhook\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Message\WebhookEventMessage;
use Shopware\Core\Framework\Webhook\Outbox\OutboxEventRepository;
use Shopware\Core\Framework\Webhook\Outbox\OutboxInsertResult;
use Shopware\Core\Framework\Webhook\Transport\WebhookOutboxSender;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(WebhookOutboxSender::class)]
class WebhookOutboxSenderTest extends TestCase
{
    public function testPersistsToOutbox(): void
    {
        $message = $this->createWebhookEventMessage();
        $envelope = new Envelope($message);

        $repository = $this->createMock(OutboxEventRepository::class);
        $repository->expects(static::once())
            ->method('ensureOutboxEntry')
            ->with($message)
            ->willReturn(new OutboxInsertResult(true, 42));

        $sender = new WebhookOutboxSender($repository);
        $result = $sender->send($envelope);

        static::assertSame($envelope, $result);
    }

    public function testDispatchesToBrokerOnNewEntry(): void
    {
        $message = $this->createWebhookEventMessage();
        $envelope = new Envelope($message);

        $repository = $this->createMock(OutboxEventRepository::class);
        $repository->method('ensureOutboxEntry')
            ->willReturn(new OutboxInsertResult(true, 42));

        $innerSender = $this->createMock(SenderInterface::class);
        $innerSender->expects(static::once())
            ->method('send')
            ->with($envelope)
            ->willReturn($envelope);

        $sender = new WebhookOutboxSender($repository, $innerSender);
        $sender->send($envelope);
    }

    public function testDoesNotDispatchToBrokerOnDuplicate(): void
    {
        $message = $this->createWebhookEventMessage();
        $envelope = new Envelope($message);

        $repository = $this->createMock(OutboxEventRepository::class);
        $repository->method('ensureOutboxEntry')
            ->willReturn(new OutboxInsertResult(false, 42));

        $innerSender = $this->createMock(SenderInterface::class);
        $innerSender->expects(static::never())->method('send');

        $sender = new WebhookOutboxSender($repository, $innerSender);
        $sender->send($envelope);
    }

    public function testRejectsNonWebhookMessage(): void
    {
        $repository = $this->createMock(OutboxEventRepository::class);
        $sender = new WebhookOutboxSender($repository);

        $this->expectException(InvalidArgumentException::class);
        $sender->send(new Envelope(new \stdClass()));
    }

    private function createWebhookEventMessage(): WebhookEventMessage
    {
        return new WebhookEventMessage(
            '0189a5b5c0c07272b90f8e9e5b6a4d01',
            ['body' => 'payload'],
            '0189a5b5c0c07272b90f8e9e5b6a4d02',
            '0189a5b5c0c07272b90f8e9e5b6a4d03',
            '6.7.0',
            'https://example.com/webhook',
            'test-secret',
            'en-GB',
            'en-GB',
        );
    }
}
