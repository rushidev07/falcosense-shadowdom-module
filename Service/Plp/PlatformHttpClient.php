<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service\Plp;

use Psr\Log\LoggerInterface;

/**
 * The one place the PLP path talks to the platform over HTTP. Deliberately
 * separate from ProductSyncService's own cURL calls (outbound ingest, 120s
 * budget, POST) because this is the opposite job: a GET on the *render* path
 * with a sub-second budget where a slow response must be abandoned, not waited
 * on. Kept as one small injectable unit so the provider above it stays a pure
 * request->PlpResult mapping that's trivial to unit test.
 *
 * Millisecond timeouts (CURLOPT_*_MS) are why this doesn't use
 * Magento\Framework\HTTP\Client\Curl — that wrapper only exposes whole-second
 * timeouts, and "half a second, then give up" is the entire point here.
 */
class PlatformHttpClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar|array|null> $query nested arrays are encoded as filter[k][]=v
     * @param string[]                          $headers  raw header lines, e.g. ['X-Api-Key: abc']
     * @return array<string, mixed> decoded JSON object
     * @throws PlatformRequestException on transport error, timeout, non-2xx, or non-JSON body
     */
    public function getJson(string $url, array $query, array $headers, int $timeoutMs): array
    {
        $timeoutMs = max(50, $timeoutMs);

        $filtered = array_filter(
            $query,
            static fn ($v) => $v !== null && $v !== '' && $v !== []
        );
        if ($filtered !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($filtered);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 400),
            CURLOPT_NOSIGNAL          => true,
            CURLOPT_HTTPHEADER        => array_merge(['Accept: application/json'], $headers),
        ]);

        $started  = microtime(true);
        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        if ($errno !== 0 || $body === false) {
            throw new PlatformRequestException(
                sprintf('PLP request transport error (%d) after %dms: %s', $errno, $elapsedMs, $error)
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new PlatformRequestException(
                sprintf('PLP request HTTP %d after %dms', $httpCode, $elapsedMs)
            );
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new PlatformRequestException(
                sprintf('PLP request returned non-JSON body after %dms', $elapsedMs)
            );
        }

        if (($decoded['success'] ?? true) === false) {
            throw new PlatformRequestException(
                'PLP request returned success=false: ' . (string) ($decoded['error'] ?? 'unknown')
            );
        }

        if ($elapsedMs > $timeoutMs * 0.8) {
            $this->logger->warning(sprintf(
                '[SmartSearchLuma][PLP] Slow platform response: %dms (budget %dms) for %s',
                $elapsedMs,
                $timeoutMs,
                $url
            ));
        }

        return $decoded;
    }
}
