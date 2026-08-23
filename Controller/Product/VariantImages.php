<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Controller\Product;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Catalog\Api\ProductRepositoryInterface;

class VariantImages implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface        $request,
        private readonly JsonFactory             $jsonFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Configurable            $configurableType,
    ) {}

    public function execute()
    {
        $result   = $this->jsonFactory->create();
        $parentId = (int) $this->request->getParam('parent_id');

        if (!$parentId) {
            return $result->setData([]);
        }

        try {
            $parent   = $this->productRepository->getById($parentId);
            $children = $this->configurableType->getUsedProducts($parent);
            $images   = [];

            foreach ($children as $child) {
                $image = $child->getData('image');
                if ($image && $image !== 'no_selection') {
                    $images[$child->getId()] = $image;
                }
            }

            return $result->setData($images);
        } catch (\Throwable $e) {
            return $result->setData([]);
        }
    }
}
