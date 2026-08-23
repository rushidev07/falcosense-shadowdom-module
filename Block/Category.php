<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Service\SearchTokenService;

class Category extends Template
{
    public function __construct(
        Context            $context,
        private Data                $helper,
        private LayerResolver       $layerResolver,
        private SearchTokenService  $tokenService,
        array              $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSearchQuery(): string
    {
        return (string) $this->getRequest()->getParam('q', '');
    }

    /**
     * Suppressed once the Shadow DOM widget is active (isWidgetEnabled()) —
     * results.phtml's own early-return guard on this method is what keeps the
     * legacy and new implementations from ever running at the same time,
     * without any extra layout-XML conditionals. See the implementation plan's
     * two-flag design (frontend_enabled vs. widget_enabled).
     */
    public function isEnabled(): bool
    {
        return $this->helper->isFrontendEnabled() && !$this->helper->isWidgetEnabled();
    }

    public function getProductsApiUrl(): string
    {
        $endpoint = $this->helper->getEndpointUrl();
        if (!$endpoint) {
            return 'http://localhost:8080/api/v1/products';
        }
        $parts = parse_url($endpoint);
        $base  = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base . '/api/v1/products';
    }

    public function getSearchToken(): string
    {
        return $this->tokenService->getToken(0);
    }

    public function getCategoryName(): string
    {
        try {
            $layer    = $this->layerResolver->get();
            $category = $layer->getCurrentCategory();
            return (string) ($category ? $category->getName() : '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getProductsPerPage(): int
    {
        return $this->helper->getProductsPerPage();
    }

    public function getPlatformStoreId(): int
    {
        return $this->helper->getPlatformStoreId();
    }

    public function getMediaBaseUrl(): string
    {
        try {
            return rtrim($this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/') . '/catalog/product';
        } catch (\Throwable $e) {
            return '/media/catalog/product';
        }
    }

    public function getAddToCartUrl(): string
    {
        return $this->getUrl('checkout/cart/add');
    }

    public function getVariantImagesUrl(): string
    {
        return $this->getUrl('smsl/product/variantimages');
    }

    public function getSearchAnalyticsUrl(): string
    {
        $endpoint = $this->helper->getEndpointUrl();
        if (!$endpoint) {
            return 'http://localhost:8080/api/v1/analytics/search';
        }
        $parts = parse_url($endpoint);
        $base  = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base . '/api/v1/analytics/search';
    }

    public function getEventsUrl(): string
    {
        return $this->helper->getEventsEndpointUrl();
    }

    public function getSuggestUrl(): string
    {
        $endpoint = $this->helper->getEndpointUrl();
        if (!$endpoint) {
            return 'http://localhost:8080/api/v1/suggest';
        }
        $parts = parse_url($endpoint);
        $base  = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base . '/api/v1/suggest';
    }

    public function isNoResultsModalEnabled(): bool
    {
        return $this->helper->isNoResultsModalEnabled();
    }

    public function getNoResultsModalConfig(): array
    {
        return [
            'title'          => $this->helper->getNoResultsModalTitle(),
            'subtitle'       => $this->helper->getNoResultsModalSubtitle(),
            'heading'        => $this->helper->getNoResultsModalHeading(),
            'productCount'   => $this->helper->getNoResultsProductCount(),
        ];
    }
}
