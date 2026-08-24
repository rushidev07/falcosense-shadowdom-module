<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Controller\Search;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * The header search form's actual results-page destination: a clean,
 * FalcoSense-branded /fs/search?q=... URL rather than the Magento-native-
 * looking /catalogsearch/result/?q=.... Renders the identical page as
 * catalogsearch/result (see fs_search_index.xml, which reuses that handle's
 * layout rather than duplicating it) — same widget, same legacy fallback,
 * just a different address bar. Query tracking is handled the same way for
 * both URLs by SearchQueryObserver (see etc/events.xml), not duplicated
 * here.
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
