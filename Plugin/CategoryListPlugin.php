<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Plugin;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Plp\PlpContextProvider;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * When the single-source SSR grid (Block\Plp\Grid) is rendering a listing, the
 * native product collection must be empty — otherwise the page shows two grids,
 * and the native one is the drift-prone DB copy this rebuild exists to retire.
 *
 * This is deliberately the *only* thing this plugin does now:
 *  - SSR active  -> empty collection (Block\Plp\Grid renders the real grid);
 *  - otherwise   -> proceed() untouched.
 *
 * It targets a Magento core method, not a theme's block name, so it works the
 * same on Luma, Hyvä, or a bespoke theme. And it is fail-open by construction:
 * if the platform has nothing usable, PlpContextProvider->isActive() is false
 * and the native grid renders exactly as it always did.
 */
class CategoryListPlugin
{
    public function __construct(
        private readonly Data               $helper,
        private readonly PlpContextProvider $plpContext,
        private readonly CollectionFactory  $collectionFactory,
    ) {}

    public function aroundGetLoadedProductCollection(ListProduct $subject, callable $proceed)
    {
        if ($this->helper->isFrontendEnabled() && $this->plpContext->isActive()) {
            $collection = $this->collectionFactory->create();
            $collection->getSelect()->where('1 = 0');
            return $collection;
        }

        return $proceed();
    }
}
