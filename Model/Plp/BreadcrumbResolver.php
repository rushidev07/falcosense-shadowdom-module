<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Builds a category's breadcrumb trail ([name, url] from root's child down to the
 * category itself) for BreadcrumbList JSON-LD. One collection query, only ever
 * run on a cache-miss render.
 */
class BreadcrumbResolver
{
    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    public function forCategory(Category $category, int $storeId): array
    {
        $pathIds = array_values(array_filter(
            array_map('intval', explode('/', (string) $category->getPath())),
            static fn (int $id) => $id > 1
        ));

        if ($pathIds === []) {
            return [];
        }

        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStore($storeId);
            $collection->addAttributeToSelect(['name', 'url_key', 'is_active']);
            $collection->addAttributeToFilter('entity_id', ['in' => $pathIds]);
            $collection->addUrlRewriteToResult();

            $byId = [];
            foreach ($collection as $cat) {
                $byId[(int) $cat->getId()] = $cat;
            }

            $crumbs = [];
            foreach ($pathIds as $id) {
                if (!isset($byId[$id])) {
                    continue;
                }
                $cat = $byId[$id];
                if ((int) $cat->getData('is_active') !== 1) {
                    continue;
                }
                $crumbs[] = [
                    'name' => (string) $cat->getName(),
                    'url'  => (string) $cat->getUrl(),
                ];
            }

            return $crumbs;
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma][PLP] Breadcrumb resolve failed: ' . $e->getMessage());
            return [];
        }
    }
}
