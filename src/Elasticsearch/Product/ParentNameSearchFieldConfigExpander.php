<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Product;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
final class ParentNameSearchFieldConfigExpander
{
    private const NAME_FIELD = 'name';

    private const PARENT_NAME_FIELD = 'parent.name';

    private const RANKING_FACTOR = 0.8;

    /**
     * @param SearchFieldConfig[] $configs
     *
     * @return SearchFieldConfig[]
     */
    public static function extend(string $entityName, array $configs): array
    {
        foreach ($configs as $config) {
            if ($config->getField() === self::PARENT_NAME_FIELD) {
                return $configs;
            }
        }

        foreach ($configs as $config) {
            if ($entityName !== ProductDefinition::ENTITY_NAME || $config->getField() !== self::NAME_FIELD) {
                continue;
            }

            $configs[] = new SearchFieldConfig(
                self::PARENT_NAME_FIELD,
                round($config->getRanking() * self::RANKING_FACTOR, 5),
                $config->tokenize(),
                $config->isAndLogic(),
                $config->usePrefixMatch(),
            );

            break;
        }

        return $configs;
    }
}
