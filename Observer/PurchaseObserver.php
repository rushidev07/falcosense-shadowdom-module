<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Observer;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Service\CustomerEventService;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class PurchaseObserver implements ObserverInterface
{
    public function __construct(
        private readonly Data                  $helper,
        private readonly CustomerEventService  $eventService,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $observer->getData('order');

            if (!$order || !$order->getIncrementId()) {
                return;
            }

            $orderId    = $order->getId() ?: $order->getIncrementId();
            $storeId    = (int) $this->storeManager->getStore()->getId();
            $customerId = $order->getCustomerId() !== null ? (int) $order->getCustomerId() : null;

            $items = [];
            foreach ($order->getAllVisibleItems() as $item) {
                $items[] = [
                    'product_id'  => (string) $item->getProductId(),
                    'sku'         => (string) $item->getSku(),
                    'name'        => (string) $item->getName(),
                    'price'       => (float) $item->getPrice(),
                    'qty_ordered' => (float) $item->getQtyOrdered(),
                    'row_total'   => (float) $item->getRowTotal(),
                ];
            }

            if (empty($items)) {
                return;
            }

            $this->eventService->trackPurchase(
                orderId:     (string) $orderId,
                orderNumber: (string) $order->getIncrementId(),
                grandTotal:  (float) $order->getGrandTotal(),
                currency:    (string) $order->getOrderCurrencyCode(),
                items:       $items,
                customerId:  $customerId,
                storeId:     $storeId
            );
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma][Events] Purchase observer error: ' . $e->getMessage());
        }
    }
}
