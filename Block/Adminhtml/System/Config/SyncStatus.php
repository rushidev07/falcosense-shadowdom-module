<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Ahy\SmartSearchLuma\Helper\Data as SmartSearchHelper;

class SyncStatus extends Field
{
    private SmartSearchHelper $helper;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        SmartSearchHelper $helper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->helper = $helper;
    }

    public function render(AbstractElement $element): string
    {
        $lastSync = $this->helper->getLastSyncAt();

        if ($lastSync) {
            $ts       = strtotime($lastSync);
            $date     = date('M j, Y', $ts);
            $time     = date('H:i:s', $ts);
            $ago      = $this->timeAgo($ts);
            $dotColor = '#22c55e';
            $label    = "<span style='color:#166534;font-weight:700;'>Last synced:</span>"
                      . " <span style='color:#1e3a5f;font-weight:700;'>{$date} at {$time}</span>"
                      . " <span style='color:#6b7280;font-size:11px;'>({$ago})</span>";
        } else {
            $dotColor = '#f59e0b';
            $label    = "<span style='color:#92400e;font-weight:700;'>Never synced</span>"
                      . " <span style='color:#6b7280;font-size:11px;'>— run a full sync or wait for the cron to fire</span>";
        }

        return <<<HTML
<tr>
    <td colspan="4" style="padding:4px 0 8px;">
        <div style="display:inline-flex;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 14px;">
            <span style="width:9px;height:9px;border-radius:50%;background:{$dotColor};display:inline-block;flex-shrink:0;"></span>
            <span style="font-size:13px;line-height:1.4;">{$label}</span>
        </div>
    </td>
</tr>
HTML;
    }

    private function timeAgo(int $ts): string
    {
        $diff = time() - $ts;
        if ($diff < 60)     return 'just now';
        if ($diff < 3600)   return (int)($diff / 60) . ' min ago';
        if ($diff < 86400)  return (int)($diff / 3600) . ' hr ago';
        if ($diff < 604800) return (int)($diff / 86400) . ' days ago';
        return (int)($diff / 604800) . ' weeks ago';
    }
}
