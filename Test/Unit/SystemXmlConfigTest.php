<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScimAzure\Test\Unit;

use DmLab\AdminScimAzure\Block\Adminhtml\System\Config\SetupInfo;
use PHPUnit\Framework\TestCase;

/**
 * Verifies etc/adminhtml/system.xml adds the Entra setup-info field into
 * admin-scim's section, wired to the SetupInfo frontend model — so the guidance
 * actually shows up in the admin config UI when the plugin is installed.
 */
class SystemXmlConfigTest extends TestCase
{
    /** @var \DOMXPath */
    private \DOMXPath $xpath;

    protected function setUp(): void
    {
        $systemXml = dirname(__DIR__, 2) . '/etc/adminhtml/system.xml';
        self::assertFileExists($systemXml);

        $dom = new \DOMDocument();
        self::assertTrue($dom->load($systemXml));
        $this->xpath = new \DOMXPath($dom);
    }

    public function testSetupFieldExtendsTheAdminScimSection(): void
    {
        $fields = $this->xpath->query(
            '//section[@id="dmlab_admin_scim"]/group[@id="azure"]/field[@id="setup_info"]'
        );

        self::assertNotFalse($fields);
        self::assertSame(1, $fields->length, 'Entra setup_info field not found under the admin-scim section.');
    }

    public function testSetupFieldUsesTheFrontendModel(): void
    {
        $models = $this->xpath->query(
            '//section[@id="dmlab_admin_scim"]/group[@id="azure"]'
            . '/field[@id="setup_info"]/frontend_model'
        );

        self::assertNotFalse($models);
        self::assertSame(1, $models->length);
        self::assertSame(SetupInfo::class, trim($models->item(0)->textContent));
    }
}
