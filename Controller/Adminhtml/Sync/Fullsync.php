<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Controller\Adminhtml\Sync;

use Ahy\SmartSearchLuma\Helper\Data as SmartSearchHelper;
use Ahy\SmartSearchLuma\Service\SyncLockManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Fullsync extends Action
{
    const ADMIN_RESOURCE = 'Ahy_SmartSearchLuma::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory       $jsonFactory,
        private readonly SyncLockManager   $lockManager,
        private readonly SmartSearchHelper $helper,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->getRequest()->isPost()) {
            return $result->setData(['success' => false, 'message' => 'Invalid request.']);
        }

        if (!$this->helper->isEnabled()) {
            return $result->setData(['success' => false, 'message' => 'Smart Search sync is disabled. Enable it under Stores → Config → Ahy → Smart Search.']);
        }

        if (!$this->helper->getEndpointUrl()) {
            return $result->setData(['success' => false, 'message' => 'Platform Endpoint URL is not configured.']);
        }

        if (!$this->helper->getApiKey()) {
            return $result->setData(['success' => false, 'message' => 'API Key is not configured. Please set it under General Settings before syncing.']);
        }

        if ($this->lockManager->isLocked()) {
            $info    = $this->lockManager->getLockInfo();
            $started = $info['started'] ?? 'unknown';
            $source  = $info['source']  ?? 'unknown';
            return $result->setData([
                'success' => false,
                'message' => "Sync already running (via {$source}, started {$started}). Reload page to refresh status.",
            ]);
        }

        // Acquire a pre-launch lock (source=admin) so the button is disabled
        // immediately — the background command will take it over with its own PID.
        if (!$this->lockManager->acquire('admin')) {
            return $result->setData(['success' => false, 'message' => 'Could not acquire lock — another sync may have just started.']);
        }

        $php = $this->findPhpCli();
        $bin = BP . '/bin/magento';
        $log = BP . '/var/log/smartsearch-full-sync.log';

        $cmd = sprintf(
            'env -i HOME=%s PATH=%s %s %s smartsearchluma:sync:full --force < /dev/null >> %s 2>&1 &',
            escapeshellarg((string)(getenv('HOME') ?: '/tmp')),
            escapeshellarg((string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')),
            escapeshellarg($php),
            escapeshellarg($bin),
            escapeshellarg($log)
        );

        @shell_exec($cmd);

        return $result->setData([
            'success' => true,
            'message' => 'Full sync started in background. Monitor: tail -f var/log/smartsearch-full-sync.log',
        ]);
    }

    private function findPhpCli(): string
    {
        $binary = PHP_BINARY;
        $base   = basename($binary);

        // If PHP_BINARY is already a CLI binary (not a cgi/fpm variant), use it directly
        if (!str_contains($base, 'cgi') && !str_contains($base, 'fpm')) {
            return $binary;
        }

        // CGI or FPM binary — the CLI sibling is named 'php' in the same directory
        $sibling = dirname($binary) . '/php';
        if (file_exists($sibling) && is_executable($sibling)) {
            return $sibling;
        }

        // Ask the shell as last resort
        $which = trim((string) @shell_exec('which php 2>/dev/null'));
        return ($which && file_exists($which)) ? $which : $binary;
    }
}
