<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service;

use Ahy\SmartSearchLuma\Helper\Data;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Psr\Log\LoggerInterface;

class ProductSyncService
{
    public function __construct(
        private readonly Data                      $helper,
        private readonly StockRegistryInterface    $stockRegistry,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly LoggerInterface           $logger,
        private readonly UrlFinderInterface        $urlFinder,
    ) {}

    public function sync(Product $product, int $magentoStoreId = 0, ?StockItemInterface $stockItem = null): bool
    {
        $endpointUrl     = $this->helper->getEndpointUrl($magentoStoreId);
        $apiKey          = $this->helper->getApiKey($magentoStoreId);
        $platformStoreId = $this->helper->getPlatformStoreId($magentoStoreId);

        if (!$endpointUrl || !$apiKey) {
            $this->logger->warning('[SmartSearchLuma] Sync skipped: endpoint URL or API key not configured.');
            return false;
        }

        try {
            $normalized = $this->normalize($product, $magentoStoreId, $stockItem);

            $payload = [
                'batch_id' => $this->uuid4(),
                'store_id' => $platformStoreId,
                'realtime' => true,
                'products' => [$normalized],
            ];

            $code = $this->post($endpointUrl, $apiKey, $payload);

            if ($code >= 200 && $code < 300) {
                $this->logger->info(sprintf('[SmartSearchLuma][RT] Product %d synced OK (HTTP %d).', $product->getId(), $code));
                return true;
            }

            $this->logger->error(sprintf('[SmartSearchLuma][RT] Product %d sync FAILED (HTTP %d).', $product->getId(), $code));
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma][RT] Sync failed for product ' . $product->getId() . ': ' . $e->getMessage());
            return false;
        }
    }

    public function syncBatch(array $products, int $magentoStoreId = 0): int
    {
        if (empty($products)) {
            return 0;
        }

        $endpointUrl     = $this->helper->getEndpointUrl($magentoStoreId);
        $apiKey          = $this->helper->getApiKey($magentoStoreId);
        $platformStoreId = $this->helper->getPlatformStoreId($magentoStoreId);

        if (!$endpointUrl || !$apiKey) {
            $this->logger->warning('[SmartSearchLuma] Batch sync skipped: endpoint URL or API key not configured.');
            return -1;
        }

        $normalized = [];
        foreach ($products as $product) {
            try {
                $normalized[] = $this->normalize($product, $magentoStoreId);
            } catch (\Throwable $e) {
                $this->logger->warning('[SmartSearchLuma] Could not normalize product ' . $product->getId() . ': ' . $e->getMessage());
            }
        }

        if (empty($normalized)) {
            return 0;
        }

        try {
            $payload  = ['batch_id' => $this->uuid4(), 'store_id' => $platformStoreId, 'products' => $normalized];
            $httpCode = $this->post($endpointUrl, $apiKey, $payload);

            if ($httpCode >= 200 && $httpCode < 300) return count($normalized);
            if ($httpCode === 401 || $httpCode === 0)  return -1;
            if ($httpCode === 403)                      return -2;
            return -3;
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma] Batch sync exception: ' . $e->getMessage());
            return -3;
        }
    }

    private function normalize(Product $product, int $magentoStoreId, ?StockItemInterface $preloadedStockItem = null): array
    {
        $productId = (int) $product->getId();
        $stockItem = $preloadedStockItem ?? $this->stockRegistry->getStockItem($productId);
        $inStock   = (bool) $stockItem->getIsInStock();
        $qty       = (int) $stockItem->getQty();

        $categories = $this->buildCategories($product->getCategoryIds(), $magentoStoreId);

        $brand = '';
        foreach (['product_brand', 'manufacturer'] as $brandAttr) {
            if ($product->getData($brandAttr)) {
                $text = $product->getAttributeText($brandAttr);
                if (is_array($text)) $text = implode(', ', $text);
                $brand = ($text !== false && $text !== '') ? (string) $text : (string) $product->getData($brandAttr);
                if ($brand !== '') break;
            }
        }

        $excludedAttributeCodes = [
            'sku', 'name', 'description', 'short_description', 'url_key', 'url_path',
            'price', 'special_price', 'tier_price', 'minimal_price', 'min_amount', 'max_amount', 'price_rate',
            'status', 'visibility', 'product_brand', 'manufacturer',
            'image', 'small_image', 'thumbnail', 'swatch_image',
            'image_label', 'small_image_label', 'thumbnail_label', 'media_gallery',
            'meta_title', 'meta_description', 'meta_keyword',
            'page_layout', 'custom_layout', 'custom_layout_update', 'custom_design',
            'custom_design_from', 'custom_design_to', 'options_container',
            'category_ids', 'required_options', 'has_options',
            'created_at', 'updated_at', 'tax_class_id', 'quantity_and_stock_status',
            'msrp', 'msrp_display_actual_price_type',
            'price_type', 'price_view', 'sku_type', 'weight_type', 'shipment_type',
            'links_purchased_separately', 'links_title', 'samples_title', 'gift_message_available',
            'news_from_date', 'news_to_date', 'special_from_date', 'special_to_date',
        ];

        $attributes = [];
        foreach ($product->getAttributes() as $attribute) {
            $code  = $attribute->getAttributeCode();
            $label = (string) $attribute->getFrontendLabel();
            $input = $attribute->getFrontendInput();

            if (in_array($code, $excludedAttributeCodes, true) || $label === '' || preg_match('/^attribute_\d+$/i', $code)) {
                continue;
            }

            $val = $product->getData($code);
            if ($val === null || $val === '' || $val === false) {
                continue;
            }

            if (in_array($input, ['select', 'multiselect', 'swatch_visual', 'swatch_text'], true)) {
                $text = $product->getAttributeText($code);
                if ($text === false || $text === '') continue;
                $value = is_array($text) ? implode(', ', $text) : (string) $text;
            } else {
                if (is_array($val)) {
                    $scalars = array_filter($val, 'is_scalar');
                    if (empty($scalars)) continue;
                    $value = implode(', ', array_map('strval', $scalars));
                } else {
                    $value = (string) $val;
                }
                if ($value === '') continue;
            }

            $attributes[] = ['name' => $label, 'value' => $value];
        }

        $variants = [];
        if ($product->getTypeId() === 'configurable') {
            foreach ($product->getTypeInstance()->getUsedProducts($product) as $child) {
                $childStock = $this->stockRegistry->getStockItem((int) $child->getId());
                $variants[] = [
                    'variant_id'    => (string) $child->getId(),
                    'name'          => (string) ($child->getName() ?: $product->getName()),
                    'sku'           => (string) $child->getSku(),
                    'price'         => (float) ($child->getPrice() ?: $product->getPrice()),
                    'special_price' => $child->getSpecialPrice() !== null ? (float) $child->getSpecialPrice() : null,
                    'in_stock'      => (bool) $childStock->getIsInStock(),
                    'qty'           => (int) $childStock->getQty(),
                ];
            }
            $qty = 0;
            if (empty($variants)) $inStock = false;
        }

        $basePrice = (float) $product->getPrice();
        if ($basePrice <= 0 && !empty($variants)) {
            $variantPrices = array_filter(array_column($variants, 'price'), fn($p) => $p > 0);
            if (!empty($variantPrices)) $basePrice = min($variantPrices);
        }

        $parentSpecial    = $product->getSpecialPrice() !== null ? (float) $product->getSpecialPrice() : null;
        $effectiveSpecial = ($parentSpecial !== null && $parentSpecial > 0) ? $parentSpecial : null;
        if ($effectiveSpecial === null && !empty($variants)) {
            $minEffective = PHP_FLOAT_MAX;
            foreach ($variants as $v) {
                $vp  = (float) ($v['price'] ?? 0);
                $vs  = isset($v['special_price']) && $v['special_price'] !== null ? (float) $v['special_price'] : null;
                $eff = ($vs !== null && $vs > 0 && $vs < $vp) ? $vs : ($vp > 0 ? $vp : PHP_FLOAT_MAX);
                $minEffective = min($minEffective, $eff);
            }
            if ($minEffective < $basePrice && $minEffective < PHP_FLOAT_MAX) $effectiveSpecial = $minEffective;
        }

        return [
            'product_id'        => (string) $product->getId(),
            'sku'               => (string) $product->getSku(),
            'type'              => (string) $product->getTypeId(),
            'name'              => (string) $product->getName(),
            'price'             => $basePrice,
            'special_price'     => $effectiveSpecial,
            'in_stock'          => $inStock,
            'qty'               => $qty,
            'visibility'        => (int) $product->getVisibility(),
            'is_variant'        => ((int) $product->getVisibility() === 1),
            'status'            => (int) $product->getStatus(),
            'brand'             => $brand,
            'url_key'           => $this->resolveUrlKey($product, $magentoStoreId),
            'description'       => strip_tags((string) ($product->getData('description') ?: '')),
            'short_description' => strip_tags((string) ($product->getData('short_description') ?: '')),
            'images'            => ['main_image' => (string) ($product->getData('image') ?: '')],
            'categories'        => $categories,
            'attributes'        => $attributes,
            'variants'          => $variants,
        ];
    }

    private function buildCategories(array $categoryIds, int $storeId): array
    {
        if (empty($categoryIds)) return [];

        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToFilter('entity_id', ['in' => $categoryIds])
                       ->addAttributeToSelect(['name', 'path', 'url_key', 'url_path'])
                       ->setStoreId($storeId);

            $allAncestorIds = [];
            foreach ($collection as $cat) {
                foreach (array_slice(explode('/', $cat->getPath()), 2) as $id) {
                    $allAncestorIds[(int) $id] = true;
                }
            }

            $nameMap = [];
            if (!empty($allAncestorIds)) {
                $ancestors = $this->categoryCollectionFactory->create();
                $ancestors->addAttributeToFilter('entity_id', ['in' => array_keys($allAncestorIds)])
                          ->addAttributeToSelect(['name'])
                          ->setStoreId($storeId);
                foreach ($ancestors as $anc) {
                    $nameMap[(int) $anc->getId()] = (string) $anc->getName();
                }
            }

            // Build leaf category entries with full breadcrumb paths
            $categories  = [];
            $emittedIds  = [];
            foreach ($collection as $cat) {
                $pathIds   = array_slice(explode('/', $cat->getPath()), 2);
                $pathNames = array_filter(array_map(static fn($id) => $nameMap[(int) $id] ?? '', $pathIds));
                $urlPath   = (string) ($cat->getData('url_path') ?: $cat->getData('url_key') ?: '');
                $categories[] = ['id' => (string) $cat->getId(), 'name' => (string) $cat->getName(), 'path' => implode(' > ', $pathNames), 'url' => $urlPath];
                $emittedIds[(int) $cat->getId()] = true;

                // Also emit every ancestor so parent-only categories (e.g. "Bottoms")
                // are searchable in the admin even though no product is directly assigned.
                $builtPath = [];
                foreach ($pathIds as $ancId) {
                    $ancId   = (int) $ancId;
                    $ancName = $nameMap[$ancId] ?? '';
                    if ($ancName === '') continue;
                    $builtPath[] = $ancName;
                    if (!isset($emittedIds[$ancId])) {
                        $categories[] = ['id' => (string) $ancId, 'name' => $ancName, 'path' => implode(' > ', $builtPath), 'url' => ''];
                        $emittedIds[$ancId] = true;
                    }
                }
            }

            return $categories;
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma] Could not load categories: ' . $e->getMessage());
            return [];
        }
    }

    private function post(string $url, string $apiKey, array $payload): int
    {
        $json    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type: application/json', 'X-Api-Key: ' . $apiKey, 'X-Signature: sha256=' . hash_hmac('sha256', $json, $apiKey)];

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->logger->error('[SmartSearchLuma][RT] cURL error: ' . $curlErr);
            return 0;
        }

        return $httpCode;
    }

    private function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function resolveUrlKey(Product $product, int $storeId): string
    {
        $urlKey = (string) $product->getUrlKey();
        if ($urlKey !== '') return $urlKey;

        try {
            $rewrite = $this->urlFinder->findOneByData(['entity_id' => (int) $product->getId(), 'entity_type' => 'product', 'store_id' => $storeId ?: 1]);
            if ($rewrite) return preg_replace('/\.[^.]+$/', '', basename($rewrite->getRequestPath()));
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma] url_rewrite lookup failed for product ' . $product->getId() . ': ' . $e->getMessage());
        }

        return '';
    }
}
