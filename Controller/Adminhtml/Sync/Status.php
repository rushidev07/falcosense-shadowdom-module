<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Controller\Adminhtml\Sync;

use Ahy\SmartSearchLuma\Service\SyncLockManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Status extends Action
{
    const ADMIN_RESOURCE = 'Ahy_SmartSearchLuma::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory     $jsonFactory,
        private readonly SyncLockManager $lockManager,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $locked = $this->lockManager->isLocked();
        $info   = $locked ? $this->lockManager->getLockInfo() : null;

        return $this->jsonFactory->create()->setData([
            'locked'      => $locked,
            'source'      => $info['source']  ?? null,
            'started'     => $info['started'] ?? null,
            'last_result' => $this->lockManager->getLastResult(),
        ]);
    }
}
