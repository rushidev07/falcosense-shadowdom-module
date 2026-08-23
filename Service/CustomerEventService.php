<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service;

use Ahy\SmartSearchLuma\Helper\Data;
use Psr\Log\LoggerInterface;

class CustomerEventService
{
    private const COOKIE_NAME = '_ahy_vid';
    private const TIMEOUT_SEC = 2;

    public function __construct(
        private readonly Data            $helper,
        private readonly LoggerInterface $logger,
    ) {}

    public function trackRegister(int $customerId, int $storeId = 0): void
    {
        $this->send([
            'event_type'  => 'customer_register',
            'customer_id' => (string) $customerId,
        ], $storeId);
    }

    public function trackPurchase(
        string $orderId,
        string $orderNumber,
        float  $grandTotal,
        string $currency,
        array  $items,
        ?int   $customerId,
        int    $storeId = 0
    ): void {
        $payload = [
            'event_type'   => 'purchase',
            'order_id'     => $orderId,
            'order_number' => $orderNumber,
            'grand_total'  => $grandTotal,
            'currency'     => $currency,
            'items'        => $items,
        ];
        if ($customerId !== null) {
            $payload['customer_id'] = (string) $customerId;
        }
        $this->send($payload, $storeId);
    }

    private function send(array $eventData, int $storeId): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $url    = $this->helper->getEventsEndpointUrl($storeId);
        $apiKey = $this->helper->getApiKey($storeId);

        if (!$url || !$apiKey) {
            return;
        }

        $platformStoreId = $this->helper->getPlatformStoreId($storeId);

        $payload = array_merge($eventData, [
            'store_id'   => $platformStoreId,
            'visitor_id' => $_COOKIE[self::COOKIE_NAME] ?? null,
            'session_id' => session_id() ?: null,
            'ip_address' => $this->anonymizedIp(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null,
        ]);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
                'X-Signature: sha256=' . hash_hmac('sha256', $json, $apiKey),
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->logger->warning('[SmartSearchLuma][Events] cURL error for purchase: ' . $err);
            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->warning(sprintf('[SmartSearchLuma][Events] HTTP %d for purchase: %s', $httpCode, substr((string) $raw, 0, 500)));
        }
    }

    private function anonymizedIp(): ?string
    {
        $raw = null;
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = trim(explode(',', $_SERVER[$key])[0]);
                break;
            }
        }
        if ($raw === null) return null;
        if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return substr($raw, 0, strrpos($raw, '.') + 1) . '0';
        }
        if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin    = inet_pton($raw);
            $zeroed = substr($bin, 0, 6) . str_repeat("\x00", 10);
            return inet_ntop($zeroed);
        }
        return null;
    }
}
