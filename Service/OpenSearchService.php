<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service;

use Ahy\SmartSearchLuma\Helper\Data;
use Psr\Log\LoggerInterface;

class OpenSearchService
{
    public function __construct(
        private readonly Data            $helper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Fetch ranked product IDs from the search platform for a category page.
     *
     * @return array{ids: int[], total: int}
     */
    public function getCategoryProducts(string $categoryName, int $page, int $storeId = 0): array
    {
        $searchUrl = $this->helper->getSearchUrl($storeId);
        $apiKey    = $this->helper->getApiKey($storeId);

        if (!$searchUrl || !$apiKey || $categoryName === '') {
            return ['ids' => [], 'total' => 0];
        }

        $separator = str_contains($searchUrl, '?') ? '&' : '?';
        $url       = $searchUrl . $separator . http_build_query([
            'category' => $categoryName,
            'api_key'  => $apiKey,
            'page'     => $page,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_NOSIGNAL       => true,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno || $body === false || $body === '') {
            $this->logger->warning('SmartSearch PLP: request failed', [
                'category' => $categoryName,
                'page'     => $page,
                'errno'    => $errno,
            ]);
            return ['ids' => [], 'total' => 0];
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['results'])) {
            return ['ids' => [], 'total' => 0];
        }

        $ids = array_values(array_filter(
            array_map('intval', array_column($data['results'], 'product_id'))
        ));

        return [
            'ids'   => $ids,
            'total' => (int) ($data['total'] ?? count($ids)),
        ];
    }
}
