<?php declare(strict_types=1);

namespace Shopware\Tests\DevOps\Core\DevOps\StaticAnalyse\PHPStan\Rules\data\McpToolResponseTraitRule;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Mcp\Tool\McpToolResponse;

#[McpTool('test-tool', 'A valid tool with trait')]
class ToolWithTrait
{
    use McpToolResponse;

    public function __invoke(): string
    {
        return $this->success([]);
    }
}
