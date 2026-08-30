<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Controller\Search;

use Ahy\SmartSearchLuma\Service\SearchTokenService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * GET /smsl/search/token — a fresh, short-lived platform search token, with
 * explicit no-store headers.
 *
 * The token baked into the page by Block\Widget\Bootstrap goes stale the moment
 * that HTML is served from FPC/Varnish (which, with the ISR pipeline, is almost
 * always). The type-ahead overlay fetches from here instead of trusting the
 * baked value, so the whole listing/search page stays fully cacheable. The
 * fragment endpoint and add-to-cart don't need this — they authenticate
 * server-side with the API key / form key.
 */
class Token implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly SearchTokenService $tokenService,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $result->setHeader('Pragma', 'no-cache', true);

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $token = $this->tokenService->getToken($storeId);
        } catch (\Throwable $e) {
            $token = '';
        }

        if ($token === '') {
            return $result->setHttpResponseCode(503)->setData(['token' => '']);
        }

        return $result->setData(['token' => $token]);
    }
}
