<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Block\Adminhtml\System\Config;

use Ahy\SmartSearchLuma\Service\SyncLockManager;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class SyncAllButton extends Field
{
    public function __construct(
        Context $context,
        private readonly SyncLockManager $lockManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $syncUrl   = $this->getUrl('smartsearchluma/sync/fullsync');
        $statusUrl = $this->getUrl('smartsearchluma/sync/status');
        $isLocked  = $this->lockManager->isLocked();
        $info      = $isLocked ? ($this->lockManager->getLockInfo() ?? []) : [];
        $started   = htmlspecialchars($info['started'] ?? '');
        $source    = htmlspecialchars(ucfirst($info['source'] ?? ''));

        return <<<HTML
<div id="ss-btn-wrap">
    {$this->renderButtonHtml($isLocked, $started, $source)}
</div>
<div id="ss-sync-msg" style="margin-top:6px;font-weight:600;font-size:13px;"></div>

<script type="text/javascript">
(function() {
    var syncUrl      = '{$syncUrl}';
    var statusUrl    = '{$statusUrl}';
    var pollTimer    = null;
    var pollCount    = 0;
    var syncInFlight = false; // true from button click until lock is confirmed or timed out

    function formKey() {
        var fk = document.querySelector('input[name="form_key"]');
        return fk ? fk.value : '';
    }

    function showMsg(text, ok) {
        var el = document.getElementById('ss-sync-msg');
        if (!el) return;
        el.textContent = text;
        el.style.color = ok ? '#065f46' : '#991b1b';
    }

    function renderIdle() {
        var wrap = document.getElementById('ss-btn-wrap');
        if (wrap) {
            wrap.innerHTML =
                '<button type="button" id="ss-sync-btn" class="action-default scalable" onclick="ssTriggerSync()">' +
                '<span>Sync All Products Now</span></button>';
        }
    }

    function renderLocked(source, started) {
        var src  = source  ? ' via ' + source  : '';
        var time = started ? ' — started ' + started : '';
        var wrap = document.getElementById('ss-btn-wrap');
        if (wrap) {
            wrap.innerHTML =
                '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">' +
                '<button type="button" class="action-default scalable" disabled style="opacity:0.55;cursor:not-allowed;">' +
                '<span>↻ Syncing in progress...</span></button>' +
                '<span style="color:#92400e;font-size:12px;font-weight:600;">' + src + time + '</span>' +
                '</div>';
        }
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        pollCount    = 0;
        syncInFlight = false;
    }

    function onSyncFinished(lastResult) {
        stopPolling();
        renderIdle();

        if (!lastResult) {
            showMsg('Sync finished. Reload the page to see the updated Last Synced time.', true);
            return;
        }

        if (lastResult.success) {
            showMsg('✔ ' + (lastResult.message || 'Sync completed successfully.'), true);
        } else {
            showMsg('✘ Sync failed: ' + (lastResult.message || 'Unknown error.'), false);
        }
    }

    function poll() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', statusUrl, true);
        xhr.withCredentials = true;
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 0) return; // network error — keep polling

            try {
                var data = JSON.parse(xhr.responseText);

                if (data.locked) {
                    // Lock confirmed — update displayed info if needed
                    syncInFlight = false;
                    pollCount++;
                    renderLocked(data.source || '', data.started || '');
                    return;
                }

                // Lock is gone
                if (syncInFlight && pollCount === 0) {
                    // We've never seen the lock — process probably failed to start
                    // or failed before we could see it. Check last_result.
                    onSyncFinished(data.last_result || null);
                    return;
                }

                // Normal finish: lock was held and now released
                onSyncFinished(data.last_result || null);

            } catch(e) {
                // Bad JSON — keep polling silently
            }
        };
        xhr.send();
        pollCount++;
    }

    function startPolling() {
        if (pollTimer) return;
        pollCount = 0;
        // First check after 2s — fast enough to detect quick failures
        setTimeout(function() {
            poll();
            // Then every 4s
            pollTimer = setInterval(poll, 4000);
        }, 2000);
    }

    window.ssTriggerSync = function() {
        var btn = document.querySelector('#ss-sync-btn');
        if (!btn || btn.disabled) return; // prevent double-click

        btn.disabled = true;
        btn.querySelector('span').textContent = 'Starting…';
        showMsg('', true);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', syncUrl, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.withCredentials = true;

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;

            try {
                var data = JSON.parse(xhr.responseText);

                if (data.success) {
                    // Lock was pre-created by the controller — show disabled state immediately
                    syncInFlight = true;
                    renderLocked('command', '');
                    showMsg('Sync started in background…', true);
                    startPolling();
                } else {
                    btn.disabled = false;
                    btn.querySelector('span').textContent = 'Sync All Products Now';
                    showMsg(data.message || 'Failed to start sync.', false);
                }
            } catch(e) {
                btn.disabled = false;
                btn.querySelector('span').textContent = 'Sync All Products Now';
                showMsg('Unexpected response from server.', false);
            }
        };

        xhr.onerror = function() {
            btn.disabled = false;
            btn.querySelector('span').textContent = 'Sync All Products Now';
            showMsg('Network error — could not reach the server.', false);
        };

        xhr.send('form_key=' + encodeURIComponent(formKey()));
    };

    // If page loaded while sync was already running, start polling immediately
    if ({$this->bool($isLocked)}) {
        renderLocked('{$source}', '{$started}');
        startPolling();
    }
})();
</script>
HTML;
    }

    private function renderButtonHtml(bool $locked, string $started, string $source): string
    {
        if (!$locked) {
            return '<button type="button" id="ss-sync-btn" class="action-default scalable" onclick="ssTriggerSync()">'
                 . '<span>Sync All Products Now</span></button>';
        }

        $meta = '';
        if ($source)  $meta .= ' via ' . $source;
        if ($started) $meta .= ' &mdash; started ' . $started;

        return '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">'
             . '<button type="button" class="action-default scalable" disabled style="opacity:0.55;cursor:not-allowed;">'
             . '<span>&#8635; Syncing in progress...</span></button>'
             . '<span style="color:#92400e;font-size:12px;font-weight:600;">' . $meta . '</span>'
             . '</div>';
    }

    private function bool(bool $val): string
    {
        return $val ? 'true' : 'false';
    }
}
