<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Ahy\SmartSearchLuma\Helper\Data;

class AddFrontendLayoutHandle implements ObserverInterface
{
    public function __construct(private Data $helper) {}

    public function execute(Observer $observer): void
    {
        // Once the new Shadow DOM widget is active, native blocks are never removed
        // at all — the widget hides/covers native content itself, at runtime, per
        // the implementation plan §1. This legacy destructive handle (and the file
        // it activates, ahy_smartsearch_active.xml) is retired entirely once Phase 2
        // completes; this check is what makes that retirement safe to land
        // incrementally rather than as one atomic cutover.
        // The destructive handle (Luma-specific block removals) is only for the
        // oldest path. The Shadow DOM widget and the SSR grid both suppress the
        // native content themselves, at runtime, without theme-name coupling.
        if (!$this->helper->isFrontendEnabled()
            || $this->helper->isWidgetEnabled()
            || $this->helper->isPlpSsrEnabled()) {
            return;
        }

        /** @var \Magento\Framework\View\Layout\ProcessorInterface $update */
        $update = $observer->getEvent()->getLayout()->getUpdate();
        $update->addHandle('ahy_smartsearch_active');
    }
}
