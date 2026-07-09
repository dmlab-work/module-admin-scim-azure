<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScimAzure\Test\Unit\Model\Azure;

use Magento\Framework\Escaper;
use MageDevGroup\AdminScim\Model\Discovery\EndpointUrlBuilder;
use MageDevGroup\AdminScimAzure\Model\Azure\ScimSetupInfo;
use PHPUnit\Framework\TestCase;

/**
 * The setup surface must render the actual SCIM endpoint (from the core's URL
 * builder, HTML-escaped) as Entra's Tenant URL, plus the Entra provisioning
 * guidance an admin needs.
 */
class ScimSetupInfoTest extends TestCase
{
    private const ENDPOINT = 'https://magento.example/admin-scim/v2';

    /** @var EndpointUrlBuilder */
    private EndpointUrlBuilder $urlBuilder;

    /** @var Escaper */
    private Escaper $escaper;

    /** @var ScimSetupInfo */
    private ScimSetupInfo $setupInfo;

    protected function setUp(): void
    {
        $this->urlBuilder = $this->createStub(EndpointUrlBuilder::class);
        $this->urlBuilder->method('baseUrl')->willReturn(self::ENDPOINT);

        $this->escaper = $this->createStub(Escaper::class);
        $this->escaper->method('escapeHtml')->willReturnArgument(0);

        $this->setupInfo = new ScimSetupInfo($this->urlBuilder, $this->escaper);
    }

    public function testEndpointUrlComesFromTheCoreBuilder(): void
    {
        self::assertSame(self::ENDPOINT, $this->setupInfo->getEndpointUrl());
    }

    public function testHtmlRendersTheEndpointAsTenantUrl(): void
    {
        $html = $this->setupInfo->getHtml();

        self::assertStringContainsString(self::ENDPOINT, $html);
        self::assertStringContainsString('Tenant URL', $html);
    }

    public function testHtmlEscapesTheEndpointThroughTheEscaper(): void
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturn('ESCAPED_ENDPOINT');

        $html = (new ScimSetupInfo($this->urlBuilder, $escaper))->getHtml();

        self::assertStringContainsString('ESCAPED_ENDPOINT', $html);
    }

    public function testHtmlDocumentsSecretTokenAndProvisioningGuidance(): void
    {
        $html = $this->setupInfo->getHtml();

        self::assertStringContainsString('Secret Token', $html);
        self::assertStringContainsString('bearer token', $html);
        self::assertStringContainsString('active = false', $html);
    }

    public function testHtmlDocumentsThe204FriendlyResponseWithoutRequiringAToggle(): void
    {
        $html = $this->setupInfo->getHtml();

        // The core answers PATCH with 200 + resource, which Entra accepts; 204 is
        // merely also acceptable, so no per-provider response-mode toggle exists.
        self::assertStringContainsString('200', $html);
        self::assertStringContainsString('204', $html);
    }
}
