<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Controller\Ajax;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Kept for backward compatibility. Geo detection now happens client-side
 * via ipapi.co to avoid server-side IP header resolution issues.
 */
class GeoState implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        return $result->setData(['state' => '']);
    }
}
