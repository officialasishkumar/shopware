<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Message;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * @internal
 */
#[Package('framework')]
class WebhookEventMessage implements AsyncMessageInterface
{
    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $webhookHeaders
     **/
    public function __construct(
        private readonly string $webhookEventId,
        private readonly array $payload,
        private readonly ?string $appId,
        private readonly string $webhookId,
        private readonly string $shopwareVersion,
        private readonly string $url,
        private readonly ?string $secret,
        private readonly string $languageId,
        private readonly string $userLocale,
        private readonly array $webhookHeaders = [],
        private readonly ?string $appName = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getAppId(): ?string
    {
        return $this->appId;
    }

    public function getWebhookId(): string
    {
        return $this->webhookId;
    }

    public function getShopwareVersion(): string
    {
        return $this->shopwareVersion;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getWebhookEventId(): string
    {
        return $this->webhookEventId;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function getLanguageId(): ?string
    {
        return $this->languageId;
    }

    public function getUserLocale(): ?string
    {
        return $this->userLocale;
    }

    /**
     * @return array<string, string>
     */
    public function getWebhookHeaders(): array
    {
        return $this->webhookHeaders;
    }

    public function getAppName(): ?string
    {
        return $this->appName;
    }

    /**
     * Partition key = xxh128(appName) as BINARY(16).
     *
     * Default partitioning is per-app. Uses app name (not ID) so the partition
     * survives app reinstallation. Non-app webhooks share a single partition.
     */
    public function getPartitionKey(): string
    {
        return hash('xxh128', $this->appName ?? '', binary: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'webhookEventId' => $this->webhookEventId,
            'payload' => $this->payload,
            'appId' => $this->appId,
            'webhookId' => $this->webhookId,
            'shopwareVersion' => $this->shopwareVersion,
            'url' => $this->url,
            'secret' => $this->secret,
            'languageId' => $this->languageId,
            'userLocale' => $this->userLocale,
            'webhookHeaders' => $this->webhookHeaders,
            'appName' => $this->appName,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // Handle legacy serialized blobs (pre-patch): PHP's default serialization produces
        // mangled keys for private properties ("\0ClassName\0prop"). Normalize them to plain keys.
        if (!isset($data['webhookEventId'])) {
            $normalized = [];
            foreach ($data as $key => $value) {
                $pos = strrpos($key, "\0");
                $normalized[$pos !== false ? substr($key, $pos + 1) : $key] = $value;
            }
            $data = $normalized;
        }

        $this->webhookEventId = $data['webhookEventId'];
        $this->payload = $data['payload'];
        $this->appId = $data['appId'];
        $this->webhookId = $data['webhookId'];
        $this->shopwareVersion = $data['shopwareVersion'];
        $this->url = $data['url'];
        $this->secret = $data['secret'];
        $this->languageId = $data['languageId'];
        $this->userLocale = $data['userLocale'];
        $this->webhookHeaders = $data['webhookHeaders'] ?? [];
        $this->appName = $data['appName'] ?? null;
    }
}
