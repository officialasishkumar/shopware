<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Product\SearchKeyword;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilder;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchTermInterpreterInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\SearchPattern;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\SearchTerm;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversClass(ProductSearchBuilder::class)]
class ProductSearchBuilderTest extends TestCase
{
    public function testFallbackToCriteriaTermWhenSearchKeywordIndexingIsDisabled(): void
    {
        $termInterpreter = $this->createMock(ProductSearchTermInterpreterInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $searchBuilder = new ProductSearchBuilder(
            $termInterpreter,
            $logger,
            20,
            false
        );

        $mockSalesChannelContext = $this->createMock(SalesChannelContext::class);
        $mockSalesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $criteria = new Criteria();
        $request = new Request();
        $request->query->set('search', 'ring saphir');

        $termInterpreter->expects($this->never())->method('interpret');

        $searchBuilder->build($request, $criteria, $mockSalesChannelContext);

        static::assertSame('ring saphir', $criteria->getTerm());
    }

    public function testSearchTermMaxLengthReached(): void
    {
        $termInterpreter = $this->createMock(ProductSearchTermInterpreterInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $searchBuilder = new ProductSearchBuilder(
            $termInterpreter,
            $logger,
            20
        );

        $mockSalesChannelContext = $this->createMock(SalesChannelContext::class);
        $mockSalesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $criteria = new Criteria();
        $request = new Request();

        $request->query->set('search', 'This search term\'s length is over 20 characters');

        $logger
            ->expects($this->once())
            ->method('notice')
            ->with(
                'The search term "{term}" was trimmed because it exceeded the maximum length of {maxLength} characters.',
                [
                    'term' => 'This search term\'s length is over 20 characters',
                    'maxLength' => 20,
                ]
            );
        $termInterpreter->expects($this->once())
            ->method('interpret')
            ->with('This search term\'s l', static::isInstanceOf(Context::class));
        $searchBuilder->build($request, $criteria, $mockSalesChannelContext);
    }

    public function testAndSearchAddsFallbackScoreQueriesForTokenGroups(): void
    {
        $pattern = new SearchPattern(new SearchTerm('elina seife 100g'));
        $pattern->setBooleanClause(true);
        $pattern->setTokenTerms([
            ['elina'],
            ['seife'],
            ['100g'],
        ]);

        $termInterpreter = $this->createMock(ProductSearchTermInterpreterInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $searchBuilder = new ProductSearchBuilder(
            $termInterpreter,
            $logger,
            20
        );

        $context = Context::createDefaultContext();
        $mockSalesChannelContext = $this->createMock(SalesChannelContext::class);
        $mockSalesChannelContext->method('getContext')->willReturn($context);
        $mockSalesChannelContext->method('getLanguageId')->willReturn($context->getLanguageId());

        $criteria = new Criteria();
        $request = new Request();
        $request->query->set('search', 'elina seife 100g');

        $termInterpreter->expects($this->once())
            ->method('interpret')
            ->with('elina seife 100g', static::isInstanceOf(Context::class))
            ->willReturn($pattern);

        $searchBuilder->build($request, $criteria, $mockSalesChannelContext);

        $fallbackQueries = array_values(array_filter(
            $criteria->getQueries(),
            static fn (ScoreQuery $query): bool => $query->getScore() === 0.001
        ));

        static::assertCount(3, $fallbackQueries);
    }
}
