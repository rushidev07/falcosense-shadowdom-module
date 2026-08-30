<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Test\Unit\Service\Plp;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use Ahy\SmartSearchLuma\Model\Plp\PlpResult;
use Ahy\SmartSearchLuma\Service\Plp\OpenSearchPlpProvider;
use Ahy\SmartSearchLuma\Service\Plp\PlatformHttpClient;
use Ahy\SmartSearchLuma\Service\Plp\PlatformRequestException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * See NativeVariantResolverTest's header note on how these run. This is the one
 * class that knows the platform's wire format, so the mapping (results/data,
 * pagination/total, image + URL resolution) is what's pinned here.
 */
class OpenSearchPlpProviderTest extends TestCase
{
    private Data&MockObject $helper;
    private PlatformHttpClient&MockObject $http;
    private StoreManagerInterface&MockObject $storeManager;
    private OpenSearchPlpProvider $provider;

    protected function setUp(): void
    {
        $this->helper = $this->createMock(Data::class);
        $this->http = $this->createMock(PlatformHttpClient::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturnCallback(
            static fn (string $type) => $type === 'media'
                ? 'https://shop.test/media/'
                : 'https://shop.test/'
        );
        $this->storeManager->method('getStore')->willReturn($store);

        $this->helper->method('getSearchUrl')->willReturn('https://platform.test/search');
        $this->helper->method('getApiKey')->willReturn('key-abc');
        $this->helper->method('getPlpPlatformTimeoutMs')->willReturn(500);

        $this->provider = new OpenSearchPlpProvider(
            $this->helper,
            $this->http,
            $this->storeManager,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function categoryQuery(): PlpQuery
    {
        return new PlpQuery(
            contextType: PlpQuery::CONTEXT_CATEGORY,
            storeId: 1,
            platformStoreId: 1,
            page: 1,
            perPage: 12,
            categoryId: 5,
            categoryName: 'Dresses',
        );
    }

    public function testMapsResultsWithPaginationBlock(): void
    {
        $this->http->method('getJson')->willReturn([
            'success' => true,
            'results' => [
                [
                    'product_id' => 101,
                    'sku' => 'A-101',
                    'name' => 'Blue Dress',
                    'url' => 'https://shop.test/blue-dress.html',
                    'image' => 'https://cdn.test/blue.jpg',
                    'price' => 80.0,
                    'special_price' => 60.0,
                    'in_stock' => true,
                ],
            ],
            'facets' => [
                ['key' => 'color', 'label' => 'Color', 'type' => 'swatch',
                 'options' => [['value' => 'Blue', 'count' => 4]]],
            ],
            'pagination' => ['total' => 240, 'page' => 1, 'per_page' => 12],
        ]);

        $result = $this->provider->fetch($this->categoryQuery());

        self::assertSame(PlpResult::SOURCE_PLATFORM, $result->source);
        self::assertSame(240, $result->total);
        self::assertCount(1, $result->items);
        self::assertSame(101, $result->items[0]->productId);
        self::assertSame(60.0, $result->items[0]->effectivePrice());
        self::assertCount(1, $result->facets);
        self::assertSame('color', $result->facets[0]->key);
    }

    public function testAcceptsDataKeyAndTopLevelTotal(): void
    {
        $this->http->method('getJson')->willReturn([
            'data' => [
                ['id' => 7, 'name' => 'X', 'url_key' => 'x-prod', 'price' => 10],
            ],
            'total' => 3,
        ]);

        $result = $this->provider->fetch($this->categoryQuery());

        self::assertSame(3, $result->total);
        self::assertSame(7, $result->items[0]->productId);
        self::assertSame('https://shop.test/x-prod.html', $result->items[0]->url, 'url_key should become an absolute .html URL');
    }

    public function testMediaRelativeImageBecomesAbsolute(): void
    {
        $this->http->method('getJson')->willReturn([
            'results' => [['product_id' => 1, 'name' => 'P', 'image' => 'a/b/img.jpg', 'price' => 1]],
        ]);

        $result = $this->provider->fetch($this->categoryQuery());

        self::assertSame('https://shop.test/media/catalog/product/a/b/img.jpg', $result->items[0]->imageUrl);
    }

    public function testMissingImageFallsBackToPlaceholder(): void
    {
        $this->http->method('getJson')->willReturn([
            'results' => [['product_id' => 1, 'name' => 'P', 'image' => 'no_selection', 'price' => 1]],
        ]);

        $result = $this->provider->fetch($this->categoryQuery());

        self::assertSame('/media/wysiwyg/no_selection.jpg', $result->items[0]->imageUrl);
    }

    public function testRowsWithoutAProductIdAreSkipped(): void
    {
        $this->http->method('getJson')->willReturn([
            'results' => [
                ['name' => 'no id here', 'price' => 5],
                ['product_id' => 9, 'name' => 'ok', 'price' => 5],
            ],
        ]);

        $result = $this->provider->fetch($this->categoryQuery());

        self::assertCount(1, $result->items);
        self::assertSame(9, $result->items[0]->productId);
    }

    public function testTransportFailureYieldsUnavailableNotAnException(): void
    {
        $this->http->method('getJson')->willThrowException(new PlatformRequestException('timeout'));

        $result = $this->provider->fetch($this->categoryQuery());

        self::assertTrue($result->isUnavailable());
    }

    public function testMissingConfigYieldsUnavailable(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getSearchUrl')->willReturn('');
        $helper->method('getApiKey')->willReturn('');

        $provider = new OpenSearchPlpProvider(
            $helper,
            $this->http,
            $this->storeManager,
            $this->createMock(LoggerInterface::class),
        );

        self::assertTrue($provider->fetch($this->categoryQuery())->isUnavailable());
    }
}
