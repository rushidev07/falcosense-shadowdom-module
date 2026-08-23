<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Block\Slider;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Ahy\SmartSearchLuma\Helper\Data as SmartSearchHelper;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

class Products extends Template
{
    private const GEO_COOKIE = 'ahy_geo_state';

    private SmartSearchHelper      $helper;
    private CustomerSession        $customerSession;
    private RegionFactory          $regionFactory;
    private CookieManagerInterface $cookieManager;
    private ?array                 $apiResponse = null;

    public function __construct(
        Context                $context,
        SmartSearchHelper      $helper,
        CustomerSession        $customerSession,
        RegionFactory          $regionFactory,
        CookieManagerInterface $cookieManager,
        array                  $data = []
    ) {
        parent::__construct($context, $data);
        $this->helper          = $helper;
        $this->customerSession = $customerSession;
        $this->regionFactory   = $regionFactory;
        $this->cookieManager   = $cookieManager;
    }

    public function isEnabled(): bool
    {
        return $this->helper->isFrontendEnabled();
    }

    public function getSliderType(): string
    {
        return (string) ($this->getData('slider_type') ?? 'newest');
    }

    public function getCustomerGeoState(): string
    {
        // Logged-in: use address region (most accurate)
        try {
            if ($this->customerSession->isLoggedIn()) {
                $customer = $this->customerSession->getCustomer();
                if ($customer && $customer->getId()) {
                    $addressIds = array_filter(array_unique([
                        (int) $customer->getDefaultBilling(),
                        (int) $customer->getDefaultShipping(),
                    ]));
                    foreach ($customer->getAddresses() as $addr) {
                        $addressIds[] = (int) $addr->getId();
                    }
                    foreach ($addressIds as $addressId) {
                        if ($addressId <= 0) continue;
                        $address = $customer->getAddressById($addressId);
                        if (!$address || !$address->getId()) continue;
                        $region = trim((string) $address->getRegion());
                        if ($region === '' && $address->getRegionId()) {
                            try {
                                $regionModel = $this->regionFactory->create()->load((int) $address->getRegionId());
                                $region = trim((string) $regionModel->getName());
                            } catch (\Throwable) {}
                        }
                        if ($region !== '') return $region;
                    }
                }
            }
        } catch (\Throwable) {}

        // Guest: read cookie only — JS writes it after consent is confirmed
        return $this->getGuestGeoState();
    }

    private function getGuestGeoState(): string
    {
        $cached = $this->cookieManager->getCookie(self::GEO_COOKIE);
        return ($cached !== null && $cached !== '') ? $cached : '';
    }

    public function getSliderProducts(): array
    {
        if ($this->apiResponse !== null) {
            return $this->apiResponse['products'] ?? [];
        }

        $endpointUrl  = $this->helper->getEndpointUrl();
        $platformBase = rtrim(preg_replace('#/api/v1/ingest/products.*#', '', $endpointUrl), '/');
        if (!$platformBase) {
            $platformBase = 'http://host.docker.internal:8080';
        }

        $url = $platformBase . '/api/v1/sliders/' . urlencode($this->getSliderType())
             . '?api_key=' . urlencode($this->helper->getApiKey())
             . '&geo_state=' . urlencode($this->getCustomerGeoState());

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->apiResponse = [];
            return [];
        }

        $this->apiResponse = json_decode($response, true) ?? [];
        return $this->apiResponse['products'] ?? [];
    }

    public function getSliderTitle(): string
    {
        if ($this->apiResponse === null) {
            $this->getSliderProducts();
        }
        $title = $this->apiResponse['title'] ?? '';
        return $title !== '' ? $title : ucwords(str_replace(['-', '_'], ' ', $this->getSliderType()));
    }

    public function getProductUrl(string $urlKey): string
    {
        return $this->getBaseUrl() . $urlKey . '.html';
    }

    public function getVariantImagesUrl(): string
    {
        return $this->getUrl('smsl/product/variantimages');
    }

    /**
     * Points at the module's own cart endpoint (VariantResolverInterface +
     * CartAdapterInterface), not Magento's native checkout/cart/add directly —
     * see the implementation plan's decision to bring slider cart-wiring in
     * line with the other three surfaces, even though slider rendering itself
     * stays out of scope.
     */
    public function getAddToCartUrl(): string
    {
        return $this->getUrl('smsl/cart/add');
    }

    public function getMediaBaseUrl(): string
    {
        try {
            return rtrim((string) $this->_storeManager->getStore()->getBaseUrl(
                \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
            ), '/') . '/catalog/product';
        } catch (\Throwable) {
            return '/media/catalog/product';
        }
    }

    public function getCacheKeyInfo(): array
    {
        return ['AHY_SLIDER', $this->getSliderType(), $this->getCustomerGeoState()];
    }

    public function getCacheLifetime(): int { return 120; }
}
