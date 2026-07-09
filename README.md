# MageDevGroup_AdminScimAzure

> Microsoft Entra ID SCIM provisioning for Magento 2 admin users.

![License](https://img.shields.io/badge/license-OSL--3.0-green) ![Magento](https://img.shields.io/badge/Magento-2.4-orange) ![PHP](https://img.shields.io/badge/PHP-8.3--8.5-blue) ![Version](https://img.shields.io/badge/version-0.0.1-lightgrey)

A thin Microsoft Entra ID (formerly Azure AD) provider plugin for the [`admin-scim`](../module-admin-scim) SCIM 2.0 server. It normalizes Entra's non-standard SCIM behaviour and documents the Entra-side setup. Entra deviates from RFC 7644 in several documented ways, so this plugin is a request normalizer that absorbs those quirks plus an admin setup surface.

## Install

```bash
composer require magedevgroup/module-admin-scim-azure
bin/magento module:enable MageDevGroup_AdminScimAzure
bin/magento setup:upgrade
```

The single `require` pulls `magedevgroup/module-admin-scim` — the whole provisioning chain installs at once.

## Set up the Entra SCIM app

1. In Magento, open **Stores → Configuration → MageDevGroup → Admin SCIM**, enable Admin SCIM, and set a **Bearer Token**.
2. Read the Tenant URL from **Stores → Configuration → MageDevGroup → Admin SCIM → Microsoft Entra ID Setup**. It is this store's SCIM base URL, e.g. `https://your-host/admin-scim/v2`.
3. In Entra, create an enterprise application, then under **Provisioning → Admin Credentials** enter:

   | Setting | Value |
   |---|---|
   | Tenant URL | the URL from step 2 |
   | Secret Token | the Bearer Token from step 1 |

4. Set the provisioning **Mapping** and **Scope**, then enable provisioning and assign users/groups.

## Supported provisioning actions

- **Create** — Entra pushes a new user → admin_user created.
- **Update** — profile changes (sent as PATCH) → admin_user updated.
- **Deactivate / reactivate** — sent by Entra as `active = false` / `active = true`.
- **Group push** — group membership → role mapping (standard SCIM, handled by `admin-scim`).

## Entra deviations handled

Entra does not send strictly RFC-compliant SCIM. This plugin's `AzureRequestNormalizer` rewrites each deviation before it reaches the core:

- **Flat complex-attribute PATCH** — Entra sends `"urn:…:User.attr"` (or `:attr`) flat instead of nesting the attribute inside its schema-extension object. Un-flattened to the nested form, in PATCH ops and POST/PUT bodies.
- **ADD vs REPLACE inconsistency** — Entra uses `add` and `replace` interchangeably for the same single-valued attribute; both are accepted (the core already treats them identically per RFC §3.5.2.1).
- **Group-member remove** — Entra puts the member in the op's `value` instead of a `path` filter (RFC §3.5.2.2); handled naively this can strip *every* member. Rewritten to a `members[value eq "<id>"]` filtered remove per member, so only the named member leaves.
- **PATCH response** — the core answers `200` with the updated resource, which Entra accepts (a `204 No Content` is also acceptable to Entra, so no response-mode toggle is needed).

## How it works

Entra pushes SCIM 2.0 requests to the `admin-scim` endpoint. This plugin registers `AzureRequestNormalizer` into `admin-scim`'s `RequestNormalizerInterface` seam (di-merged under the `azure` key). It rewrites the deviations above and passes everything else through unchanged; the actual admin-user provisioning and role mapping happen in `admin-scim`.

## Requirements

- Magento **2.4.x**
- PHP **8.3 – 8.5**
- `magedevgroup/module-admin-scim` (installed automatically)

## License

[OSL-3.0](LICENSE) © MageDevGroup. Commercial licensing and support: <https://magedevgroup.com>.
