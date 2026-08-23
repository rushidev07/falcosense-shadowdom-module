<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Observer;

use Ahy\SmartSearchLuma\Helper\Data;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires on: controller_action_postdispatch_catalogsearch_result_index
 * Sends search query to /api/v1/analytics/search as a server-side fallback.
 * The JS trackSearch() in results.phtml sends the actual result_count + response_time;
 * this observer just ensures the query is recorded even if JS is unavailable.
 */
class SearchQueryObserver implements ObserverInterface
{
    public function __construct(
        private readonly Data                  $helper,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface      $request,
        private readonly LoggerInterface       $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        try {
            $queryText = trim((string) $this->request->getParam('q', ''));
            if (strlen($queryText) < 1) {
                return;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();
            $this->send($queryText, $storeId);
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma] SearchQueryObserver error: ' . $e->getMessage());
        }
    }

    private function send(string $query, int $storeId): void
    {
        $ingestUrl = $this->helper->getEndpointUrl($storeId);
        if (!$ingestUrl) {
            return;
        }

        $parts  = parse_url($ingestUrl);
        $base   = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        $url    = $base . '/api/v1/analytics/search';
        $apiKey = $this->helper->getApiKey($storeId);

        if (!$apiKey) {
            return;
        }

        $payload = json_encode(['query' => $query, 'result_count' => 0, 'response_time_ms' => 0, 'page' => 1]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: ' . $apiKey],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
