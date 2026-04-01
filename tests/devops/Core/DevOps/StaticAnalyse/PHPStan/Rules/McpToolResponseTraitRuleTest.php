<?php declare(strict_types=1);

namespace Shopware\Tests\DevOps\Core\DevOps\StaticAnalyse\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Shopware\Core\DevOps\StaticAnalyze\PHPStan\Rules\McpToolResponseTraitRule;

/**
 * @internal
 *
 * @extends RuleTestCase<McpToolResponseTraitRule>
 */
class McpToolResponseTraitRuleTest extends RuleTestCase
{
    public function testToolWithTraitPasses(): void
    {
        $this->analyse([
            __DIR__ . '/data/McpToolResponseTraitRule/ToolWithTrait.php',
        ], []);
    }

    public function testToolWithoutTraitFails(): void
    {
        $this->analyse([
            __DIR__ . '/data/McpToolResponseTraitRule/ToolWithoutTrait.php',
        ], [[
            'MCP tools with #[McpTool] attribute must use the McpToolResponse trait.',
            7,
        ]]);
    }

    public function testClassWithoutAttributePasses(): void
    {
        $this->analyse([
            __DIR__ . '/data/McpToolResponseTraitRule/NotATool.php',
        ], []);
    }

    protected function getRule(): Rule
    {
        return new McpToolResponseTraitRule();
    }
}
