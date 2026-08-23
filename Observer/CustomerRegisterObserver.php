<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Observer;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Service\CustomerEventService;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CustomerRegisterObserver implements ObserverInterface
{
    public function __construct(
        private readonly Data                 $helper,
        private readonly CustomerEventService $eventService,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface      $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        try {
            $customer = $observer->getData('customer');
            if (!$customer || !$customer->getId()) {
                return;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();
            $this->eventService->trackRegister((int) $customer->getId(), $storeId);
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma] CustomerRegister observer error: ' . $e->getMessage());
        }
    }
}
