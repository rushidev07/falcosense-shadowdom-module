<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Asset\Repository as AssetRepository;

class VersionInfo extends Field
{
    private AssetRepository $assetRepo;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        AssetRepository $assetRepo,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->assetRepo = $assetRepo;
    }

    public function render(AbstractElement $element): string
    {
        $imageUrl = $this->assetRepo->getUrl('Ahy_SmartSearchLuma::images/smart-search-banner.png');

        return <<<HTML
<tr>
    <td colspan="4" style="padding:0 0 8px 0;">
        <img src="{$imageUrl}" alt="Smart Search by Ahy Consulting" style="max-width:340px;width:100%;display:block;border-radius:4px;" />
        <div style="margin-top:4px;font-size:13px;color:#666;">
            by <a href="https://ahyconsulting.com" target="_blank" style="color:#1979c3;">Ahy Consulting.</a>
        </div>
    </td>
</tr>
HTML;
    }
}
