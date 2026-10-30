<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScimAzure\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * composer.json and module.xml declare what the code depends on; the version comes from the git tag.
 */
class PackagingTest extends TestCase
{
    public function testComposerVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->composer()['version'] ?? '');
    }

    public function testComposerRequiresCoreAndMagentoModules(): void
    {
        $require = $this->composer()['require'];

        self::assertSame('~8.2.0||~8.3.0||~8.4.0||~8.5.0', $require['php'] ?? null);
        self::assertSame('^103.0.7', $require['magento/framework'] ?? null);
        self::assertSame('^0.2.0', $require['dmlab/module-admin-scim'] ?? null);
        self::assertSame('^102.0.7', $require['magento/module-backend'] ?? null);
        self::assertSame('^101.2.7', $require['magento/module-config'] ?? null);
    }

    public function testImportedMagentoModulesAreRequiredInComposer(): void
    {
        $require = $this->composer()['require'];

        foreach ($this->importedMagentoModules() as $module => $file) {
            $package = 'magento/module-' . strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $module));
            self::assertArrayHasKey($package, $require, $file . ' uses Magento\\' . $module);
        }
    }

    public function testModuleXmlVersionAndSequence(): void
    {
        $xml = simplexml_load_file($this->moduleRoot() . '/etc/module.xml');
        self::assertNotFalse($xml);

        $sequence = [];
        foreach ($xml->module->sequence->module as $module) {
            $sequence[] = (string)$module['name'];
        }

        self::assertSame('0.2.0', (string)$xml->module['setup_version']);
        self::assertContains('DmLab_AdminScim', $sequence);
        self::assertContains('Magento_Config', $sequence);
    }

    /**
     * Magento modules (other than Framework) imported by non-test code, mapped to an importing file.
     *
     * @return array<string, string>
     */
    private function importedMagentoModules(): array
    {
        $root = $this->moduleRoot();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $imported = [];
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(Test|vendor|docs)/#', $relative)) {
                continue;
            }
            preg_match_all('/^use Magento\\\\(\w+)\\\\/m', (string)file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $module) {
                if ($module !== 'Framework') {
                    $imported[$module] = $relative;
                }
            }
        }
        self::assertNotEmpty($imported);

        return $imported;
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        return json_decode(
            (string)file_get_contents($this->moduleRoot() . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
