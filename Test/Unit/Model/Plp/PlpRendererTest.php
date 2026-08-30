<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Test\Unit\Model\Plp;

use Ahy\SmartSearchLuma\Model\Plp\PlpItem;
use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use Ahy\SmartSearchLuma\Model\Plp\PlpRenderer;
use Ahy\SmartSearchLuma\Model\Plp\PlpResult;
use Magento\Framework\Escaper;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\TestCase;

/**
 * See NativeVariantResolverTest's header note on how these run. PlpRenderer is
 * the single grid renderer — its output is both the crawlable initial grid and
 * the fragment-swap payload, so the markup contract is pinned here.
 */
class PlpRendererTest extends TestCase
{
    private PlpRenderer $renderer;

    protected function setUp(): void
    {
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        $escaper->method('escapeHtmlAttr')->willReturnCallback(static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        $escaper->method('escapeUrl')->willReturnCallback(static fn ($v) => (string) $v);

        $price = $this->createMock(PriceCurrencyInterface::class);
        $price->method('format')->willReturnCallback(
            static fn ($amount) => '$' . number_format((float) $amount, 2)
        );

        $this->renderer = new PlpRenderer($escaper, $price);
    }

    private function query(int $page = 1, string $sort = 'position'): PlpQuery
    {
        return new PlpQuery(
            contextType: PlpQuery::CONTEXT_CATEGORY,
            storeId: 1,
            platformStoreId: 1,
            page: $page,
            perPage: 12,
            sort: $sort,
            categoryId: 5,
            categoryName: 'Dresses',
        );
    }

    private function result(int $page = 1, int $total = 47): PlpResult
    {
        return new PlpResult(
            items: [
                new PlpItem(101, 'A-101', 'Linen Dress', 'https://shop.test/linen.html', 'https://cdn.test/a.jpg', 120.0, 90.0),
                new PlpItem(102, 'A-102', 'Cotton Dress', 'https://shop.test/cotton.html', 'https://cdn.test/b.jpg', 60.0),
            ],
            facets: [],
            total: $total,
            page: $page,
            perPage: 12,
            source: PlpResult::SOURCE_PLATFORM,
            fetchedAt: 1_700_000_000,
        );
    }

    public function testRendersWrapperWithSourceAndTotals(): void
    {
        $html = $this->renderer->render($this->result(), $this->query(), 'https://shop.test/dresses');

        self::assertStringContainsString('<div class="fs-plp" data-fs-source="platform"', $html);
        self::assertStringContainsString('data-fs-total="47"', $html);
        self::assertStringContainsString('data-fs-page="1"', $html);
    }

    public function testEmbedsThePayloadItHtmlWasRenderedFrom(): void
    {
        $html = $this->renderer->render($this->result(), $this->query(), 'https://shop.test/dresses');

        self::assertStringContainsString('<script type="application/json" class="fs-plp-payload">', $html);
        self::assertMatchesRegularExpression('/"product_id":\s*101/', $html);
        // no raw </script> can appear inside the JSON
        $payload = $this->extractPayload($html);
        self::assertArrayHasKey('result', $payload);
        self::assertSame(101, $payload['result']['items'][0]['product_id']);
    }

    public function testRendersOneCardPerItemWithAbsoluteUrls(): void
    {
        $html = $this->renderer->render($this->result(), $this->query(), 'https://shop.test/dresses');

        self::assertSame(2, substr_count($html, 'class="fs-plp-card"'));
        self::assertStringContainsString('href="https://shop.test/linen.html"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    public function testSalePriceRendersBothPrices(): void
    {
        $html = $this->renderer->render($this->result(), $this->query(), 'https://shop.test/dresses');

        self::assertStringContainsString('fs-plp-card-price--sale', $html);
        self::assertStringContainsString('$90.00', $html);
        self::assertStringContainsString('<s class="fs-plp-price-was">$120.00</s>', $html);
    }

    public function testPaginationLinksArePresentAndReal(): void
    {
        $html = $this->renderer->render($this->result(2, 47), $this->query(2), 'https://shop.test/dresses?p=2');

        self::assertStringContainsString('rel="prev"', $html);
        self::assertStringContainsString('rel="next"', $html);
        self::assertStringContainsString('href="https://shop.test/dresses"', $html);       // prev -> page 1, no ?p
        self::assertStringContainsString('href="https://shop.test/dresses?p=3"', $html);   // next
        self::assertStringContainsString('Page 2 of 4', $html);
    }

    public function testSinglePageHasNoPagination(): void
    {
        $html = $this->renderer->render($this->result(1, 2), $this->query(), 'https://shop.test/dresses');

        self::assertStringNotContainsString('fs-plp-pagination', $html);
    }

    private function extractPayload(string $html): array
    {
        self::assertMatchesRegularExpression(
            '#<script type="application/json" class="fs-plp-payload">(.*?)</script>#s',
            $html,
            'payload script present'
        );
        preg_match('#<script type="application/json" class="fs-plp-payload">(.*?)</script>#s', $html, $m);

        return json_decode($m[1], true) ?? [];
    }
}
