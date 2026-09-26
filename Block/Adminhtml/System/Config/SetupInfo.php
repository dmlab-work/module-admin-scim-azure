<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScimAzure\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use DmLab\AdminScimAzure\Model\Azure\ScimSetupInfo;

/**
 * System-config frontend model rendering the Entra SCIM setup info block.
 *
 * Thin adapter: the field carries no stored value; it renders the Tenant URL and
 * setup guidance from {@see ScimSetupInfo} into the config form.
 */
class SetupInfo extends Field
{
    /**
     * @param Context $context
     * @param ScimSetupInfo $setupInfo
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ScimSetupInfo $setupInfo,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->setupInfo->getHtml();
    }
}
