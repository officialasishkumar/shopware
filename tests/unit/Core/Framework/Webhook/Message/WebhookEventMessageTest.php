<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Webhook\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Webhook\Message\WebhookEventMessage;

/**
 * @internal
 */
#[CoversClass(WebhookEventMessage::class)]
class WebhookEventMessageTest extends TestCase
{
    public function testSerializeRoundtrip(): void
    {
        $message = new WebhookEventMessage(
            'evt-123',
            ['body' => 'payload'],
            'app-1',
            'wh-1',
            '6.7.0',
            'https://example.com/hook',
            's3cr3t',
            Defaults::LANGUAGE_SYSTEM,
            'en-GB',
            ['X-Custom' => 'val'],
            'MyApp',
        );

        $restored = unserialize(serialize($message));

        static::assertInstanceOf(WebhookEventMessage::class, $restored);
        static::assertSame('evt-123', $restored->getWebhookEventId());
        static::assertSame(['body' => 'payload'], $restored->getPayload());
        static::assertSame('app-1', $restored->getAppId());
        static::assertSame('wh-1', $restored->getWebhookId());
        static::assertSame('6.7.0', $restored->getShopwareVersion());
        static::assertSame('https://example.com/hook', $restored->getUrl());
        static::assertSame('s3cr3t', $restored->getSecret());
        static::assertSame(Defaults::LANGUAGE_SYSTEM, $restored->getLanguageId());
        static::assertSame('en-GB', $restored->getUserLocale());
        static::assertSame(['X-Custom' => 'val'], $restored->getWebhookHeaders());
        static::assertSame('MyApp', $restored->getAppName());
    }

    public function testUnserializeLegacyMangledKeyFormat(): void
    {
        // Pre-patch PHP serialized WebhookEventMessage without __serialize() produces mangled keys:
        // "\0Shopware\Core\Framework\Webhook\Message\WebhookEventMessage\0webhookEventId" etc.
        // Also missing appName and webhookHeaders (added in this patch).
        $fqcn = WebhookEventMessage::class;
        $prefix = "\0" . $fqcn . "\0";

        $properties = [
            $prefix . 'webhookEventId' => 'evt-legacy',
            $prefix . 'payload' => ['body' => 'old-payload'],
            $prefix . 'appId' => 'app-old',
            $prefix . 'webhookId' => 'wh-old',
            $prefix . 'shopwareVersion' => '6.5.0',
            $prefix . 'url' => 'https://legacy.example.com',
            $prefix . 'secret' => 'old-secret',
            $prefix . 'languageId' => Defaults::LANGUAGE_SYSTEM,
            $prefix . 'userLocale' => 'de-DE',
        ];

        // Build a raw serialized string using mangled keys (exactly what PHP produces without __serialize)
        $blob = 'O:' . \strlen($fqcn) . ':"' . $fqcn . '":' . \count($properties) . ':{';
        foreach ($properties as $key => $value) {
            $blob .= serialize($key) . serialize($value);
        }
        $blob .= '}';

        $restored = unserialize($blob);

        static::assertInstanceOf(WebhookEventMessage::class, $restored);
        static::assertSame('evt-legacy', $restored->getWebhookEventId());
        static::assertSame(['body' => 'old-payload'], $restored->getPayload());
        static::assertSame('app-old', $restored->getAppId());
        static::assertSame('wh-old', $restored->getWebhookId());
        static::assertSame('6.5.0', $restored->getShopwareVersion());
        static::assertSame('https://legacy.example.com', $restored->getUrl());
        static::assertSame('old-secret', $restored->getSecret());
        static::assertSame('de-DE', $restored->getUserLocale());
        // Fields not present in legacy format default gracefully
        static::assertNull($restored->getAppName());
        static::assertSame([], $restored->getWebhookHeaders());
    }

    public function testPartitionKeyIsPerApp(): void
    {
        $msg1 = new WebhookEventMessage('e1', [], 'a1', 'wh-1', '6.7', 'https://x.com', null, 'l', 'en', [], 'AppA');
        $msg2 = new WebhookEventMessage('e2', [], 'a1', 'wh-2', '6.7', 'https://x.com', null, 'l', 'en', [], 'AppA');
        $msg3 = new WebhookEventMessage('e3', [], 'a2', 'wh-3', '6.7', 'https://x.com', null, 'l', 'en', [], 'AppB');

        // Same app → same partition (regardless of webhook ID)
        static::assertSame($msg1->getPartitionKey(), $msg2->getPartitionKey());

        // Different app → different partition
        static::assertNotSame($msg1->getPartitionKey(), $msg3->getPartitionKey());

        // Partition key is BINARY(16)
        static::assertSame(16, \strlen($msg1->getPartitionKey()));
    }

    public function testPartitionKeyForNonAppWebhook(): void
    {
        $msg1 = new WebhookEventMessage('e1', [], null, 'wh-1', '6.7', 'https://x.com', null, 'l', 'en');
        $msg2 = new WebhookEventMessage('e2', [], null, 'wh-2', '6.7', 'https://x.com', null, 'l', 'en');

        // Non-app webhooks share a single partition
        static::assertSame($msg1->getPartitionKey(), $msg2->getPartitionKey());
        static::assertSame(hash('xxh128', '', binary: true), $msg1->getPartitionKey());
    }
}
