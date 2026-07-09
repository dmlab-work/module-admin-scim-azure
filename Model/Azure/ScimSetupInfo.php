<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScimAzure\Model\Azure;

use Magento\Framework\Escaper;
use MageDevGroup\AdminScim\Model\Discovery\EndpointUrlBuilder;

/**
 * Renders the admin setup surface for wiring an Entra ID enterprise-app
 * provisioning connection to this store.
 *
 * Entra's provisioning config has two inputs: the **Tenant URL** (the SCIM base
 * URL — the one value an admin can't guess, read from the core's
 * {@see EndpointUrlBuilder}) and the **Secret Token** (this store's admin-scim
 * bearer token). The rest is fixed Entra-side guidance (provisioning actions,
 * deactivation semantics). Instantiable without Magento's block stack, so it's
 * unit-testable on its own.
 *
 * On the PATCH response: the core returns `200` with the updated resource, which
 * is RFC 7644 §3.5.2 compliant and accepted by Entra (Entra *also* accepts
 * `204 No Content`, but does not require it). So no per-provider 204 toggle is
 * needed — the guidance states the response behaviour rather than changing it.
 */
class ScimSetupInfo
{
    /**
     * @param EndpointUrlBuilder $endpointUrlBuilder
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly EndpointUrlBuilder $endpointUrlBuilder,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * The SCIM base URL to paste into Entra's Tenant URL field (e.g. `https://host/admin-scim/v2`).
     */
    public function getEndpointUrl(): string
    {
        return $this->endpointUrlBuilder->baseUrl();
    }

    /**
     * Info-panel HTML for the admin config field: Tenant URL plus Entra provisioning setup steps.
     */
    public function getHtml(): string
    {
        $endpoint = $this->escaper->escapeHtml($this->getEndpointUrl());

        return <<<HTML
<div class="magedevgroup-admin-scim-azure-setup">
    <p>In your Entra ID enterprise application (Provisioning &rarr; Admin Credentials),
        use these settings:</p>
    <ul>
        <li><strong>Tenant URL:</strong> <code>{$endpoint}</code></li>
        <li><strong>Secret Token:</strong> the admin-scim bearer token (see below).</li>
        <li><strong>Provisioning actions:</strong> Create, Update and Deactivate users,
            plus group push (deactivation is sent as <code>active = false</code>).</li>
    </ul>
    <p>Entra's flat PATCH, ADD/REPLACE and group-member-remove deviations are
        normalized automatically — no Entra-side workaround needed. The server
        answers PATCH with <code>200</code> and the updated resource, which Entra
        accepts (a <code>204 No Content</code> is also acceptable to Entra, so no
        response-mode toggle is required).</p>
    <p>Use the token configured under
        <em>Stores &rarr; Configuration &rarr; MageDevGroup &rarr; Admin SCIM &rarr; Bearer Token</em>,
        and enable Admin SCIM there first.</p>
</div>
HTML;
    }
}
