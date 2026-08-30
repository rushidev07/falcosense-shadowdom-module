<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Plugin;

use Ahy\SmartSearchLuma\Helper\Data;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Sends /catalogsearch/result/?q=... to /fs/search?q=... with a 301.
 *
 * Why a redirect and not the route override: replacing Magento's own
 * Result\Index controller (see the commented-out block in etc/frontend/routes.xml)
 * trips a pre-existing "Undefined array key 1" during layout generation on this
 * install. Redirecting instead means the stock controller's execute() — and the
 * layout it would build, plus the eager Elasticsearch collection load it does
 * before rendering — never runs. One canonical search URL, no wasted query, no
 * fatal.
 *
 * Only fires when there's an actual text query and the module's frontend is on;
 * brand/other layered-nav-on-search requests fall through to stock behaviour.
 */
class CatalogSearchRedirectPlugin
{
    public function __construct(
        private readonly Data $helper,
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
    ) {
    }

    /**
     * @param \Magento\CatalogSearch\Controller\Result\Index $subject
     * @param callable $proceed
     * @return ResultInterface|\Magento\Framework\App\ResponseInterface
     */
    public function aroundExecute($subject, callable $proceed)
    {
        $query = trim((string) $this->request->getParam('q', ''));

        if ($query === '' || !$this->helper->isFrontendEnabled()) {
            return $proceed();
        }

        /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setHttpResponseCode(301);
        $redirect->setPath('fs/search', ['_query' => ['q' => $query], '_secure' => true]);

        return $redirect;
    }
}
