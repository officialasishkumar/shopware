<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Outbox;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
#[Package('framework')]
final readonly class OutboxInsertResult
{
    public function __construct(
        public bool $isNew,
        public int $sequence,
    ) {
    }
}
