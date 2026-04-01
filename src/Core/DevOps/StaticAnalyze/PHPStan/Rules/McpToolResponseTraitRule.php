<?php declare(strict_types=1);

namespace Shopware\Core\DevOps\StaticAnalyze\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Shopware\Core\Framework\Log\Package;

/**
 * @implements Rule<Class_>
 *
 * @internal
 */
#[Package('framework')]
class McpToolResponseTraitRule implements Rule
{
    private const MCP_TOOL_ATTRIBUTE = 'Mcp\Capability\Attribute\McpTool';
    private const MCP_TOOL_RESPONSE_TRAIT = 'Shopware\Core\Framework\Mcp\Tool\McpToolResponse';

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Class_) {
            return [];
        }

        if (!$this->hasMcpToolAttribute($node)) {
            return [];
        }

        if ($this->usesMcpToolResponseTrait($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('MCP tools with #[McpTool] attribute must use the McpToolResponse trait.')
                ->identifier('shopware.mcpToolMissingResponseTrait')
                ->build(),
        ];
    }

    private function hasMcpToolAttribute(Class_ $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attribute) {
                if ($attribute->name->toString() === self::MCP_TOOL_ATTRIBUTE) {
                    return true;
                }
            }
        }

        return false;
    }

    private function usesMcpToolResponseTrait(Class_ $node): bool
    {
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                if ($trait->toString() === self::MCP_TOOL_RESPONSE_TRAIT) {
                    return true;
                }
            }
        }

        return false;
    }
}
