<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Given a set of product IDs, works out every cache tag whose listing pages
 * could have changed — the products themselves, their directly-assigned
 * categories, and every ancestor category (an anchor "Women" page shows a
 * product added only to "Women > Dresses", so it has to be purged too).
 *
 * Returned tags are Magento's own (cat_p_*, cat_c_*), so they line up with what
 * Block\Plp\Grid::getIdentities() emits and with what CachedPlpProvider tags its
 * snapshots with — one purge covers FPC, Varnish, and the snapshot cache.
 */
class AffectedCategoryResolver
{
    private const MAX_TAGS = 400;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param int[] $productIds
     * @return string[] cache tags
     */
    public function tagsForProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }

        $tags = [];
        foreach ($productIds as $id) {
            $tags['cat_p_' . $id] = true;
        }

        foreach ($this->categoryIdsForProducts($productIds) as $categoryId) {
            $tags['cat_c_' . $categoryId] = true;
        }

        return array_slice(array_keys($tags), 0, self::MAX_TAGS);
    }

    /**
     * @param int[] $productIds
     * @return int[] every category id + ancestor id the products belong to
     */
    private function categoryIdsForProducts(array $productIds): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(['ccp' => $this->resourceConnection->getTableName('catalog_category_product')], [])
                ->join(
                    ['cce' => $this->resourceConnection->getTableName('catalog_category_entity')],
                    'cce.entity_id = ccp.category_id',
                    ['path']
                )
                ->where('ccp.product_id IN (?)', $productIds)
                ->group('cce.entity_id');

            $ids = [];
            foreach ($connection->fetchCol($select) as $path) {
                foreach (explode('/', (string) $path) as $pathId) {
                    $pathId = (int) $pathId;
                    if ($pathId > 1) { // skip root (1) and the "0" sentinel
                        $ids[$pathId] = true;
                    }
                }
            }

            return array_keys($ids);
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma][PLP] Could not resolve affected categories: ' . $e->getMessage());
            return [];
        }
    }
}
