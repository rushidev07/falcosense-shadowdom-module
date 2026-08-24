<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Controller\Result;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Lightweight replacement for Magento\CatalogSearch\Controller\Result\Index,
 * reached because etc/frontend/routes.xml gives this module's "catalogsearch"
 * route priority over Magento_CatalogSearch's own.
 *
 * The original controller loads a full CatalogSearch product collection
 * (real DB + search-engine work) before layout even renders — even when the
 * native search.result block has been removed from layout, since that load
 * happens in the controller itself, not the block. This replacement skips
 * that entirely: it only sets the page title and returns the layout, letting
 * the widget's own JS fetch results from the platform API. Magento derives
 * the layout handle name ("catalogsearch_result_index") from the URL's
 * route/controller/action structure, not from which class actually handles
 * it, so this swap is invisible to Block\Widget\Bootstrap::resolvePageType()
 * and the existing catalogsearch_result_index.xml layout — neither needs to
 * change for this to work.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory      $pageFactory,
        private readonly RequestInterface $request,
    ) {
    }

    public function execute()
    {
        $query = trim((string) $this->request->getParam('q', ''));
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__("Search results for: '%1'", $query));
        return $page;
    }
}
