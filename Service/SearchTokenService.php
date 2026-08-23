<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service;

use Ahy\SmartSearchLuma\Helper\Data;
use Psr\Log\LoggerInterface;

/**
 * Fetches a short-lived search token from the platform and caches it locally.
 * The real API key is only ever sent server-side (PHP → platform).
 * The browser receives only the token.
 */
class SearchTokenService
{
    private const CACHE_FILE_PREFIX = '/tmp/smartsearchluma_token_';
    private const REFRESH_BEFORE   = 300;

    public function __construct(
        private readonly Data            $helper,
        private readonly LoggerInterface $logger,
    ) {}

    public function getToken(int $magentoStoreId = 0): string
    {
        $cacheFile = self::CACHE_FILE_PREFIX . $magentoStoreId . '.json';

        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (!empty($cached['token']) && !empty($cached['expires_at'])) {
                if (strtotime($cached['expires_at']) > time() + self::REFRESH_BEFORE) {
                    return $cached['token'];
                }
            }
        }

        $token = $this->fetchFromPlatform($magentoStoreId);
        if ($token !== null) {
            file_put_contents($cacheFile, json_encode($token), LOCK_EX);
            return $token['token'];
        }

        return '';
    }

    private function fetchFromPlatform(int $magentoStoreId): ?array
    {
        $endpointBase = rtrim((string) $this->helper->getEndpointUrl($magentoStoreId), '/');
        $apiKey       = $this->helper->getApiKey($magentoStoreId);

        if (!$endpointBase || !$apiKey) {
            $this->logger->warning('[SmartSearchLuma] SearchTokenService: endpoint or API key not configured.');
            return null;
        }

        $tokenUrl = preg_replace('#/api/v1/ingest.*#', '', $endpointBase) . '/api/v1/auth/token';

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode !== 200) {
            $this->logger->error(sprintf(
                '[SmartSearchLuma] SearchTokenService: token fetch failed (HTTP %d, cURL: %s). URL: %s',
                $httpCode, $curlErr, $tokenUrl
            ));
            return null;
        }

        $data = json_decode($raw, true);
        if (empty($data['success']) || empty($data['token'])) {
            $this->logger->error('[SmartSearchLuma] SearchTokenService: invalid token response: ' . substr($raw, 0, 200));
            return null;
        }

        return [
            'token'      => $data['token'],
            'expires_at' => $data['expires_at'],
        ];
    }
}
