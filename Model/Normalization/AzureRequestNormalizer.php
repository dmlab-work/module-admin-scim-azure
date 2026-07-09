<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScimAzure\Model\Normalization;

use MageDevGroup\AdminScim\Api\RequestNormalizerInterface;

/**
 * Microsoft Entra ID provider-quirk normalizer for the `admin-scim` server.
 *
 * Entra's SCIM client has documented deviations from RFC 7644; this rewrites its
 * request bodies into strict-RFC shape before the core handles them:
 *
 *  1. Flat complex-attribute paths — Entra addresses a schema-extension
 *     sub-attribute with the whole attribute flattened onto the path/key
 *     (`urn:…:User:department` or `urn:…:User.department`) instead of nesting it
 *     inside the extension object. Un-flattened to `{path: "urn:…:User", value:
 *     {department: …}}` (PATCH) or a nested `{"urn:…:User": {department: …}}`
 *     map (POST/PUT).
 *  2. Group-member remove via `value` — Entra puts the member(s) in the op's
 *     `value` (`{op: remove, path: "members", value: [{value: "42"}]}`) rather
 *     than a `path` filter. Handled naively (drop `path: members` ignoring
 *     `value`) that strips the *entire* group; rewritten to one
 *     `members[value eq "42"]` filtered remove per member so only the named
 *     member leaves.
 *  3. ADD vs REPLACE inconsistency — Entra uses `add` and `replace`
 *     interchangeably for the same attribute. The op verb is left intact: the
 *     core already treats them identically for the single-valued attributes it
 *     provisions (RFC §3.5.2.1), so both un-flatten to the same value shape.
 *
 * The `204 No Content` PATCH response deviation is not a request concern and is
 * handled in the Entra setup surface (Task 4), not here.
 *
 * Pure and total: any payload that isn't one of the above is returned unchanged,
 * and it never throws on unexpected input.
 */
class AzureRequestNormalizer implements RequestNormalizerInterface
{
    /**
     * A flat extension complex-attribute path/key: a SCIM schema URI ending in
     * `:User` (base or enterprise), then `:` or `.`, then the sub-attribute.
     * Group 1 = schema URI, group 2 = sub-attribute.
     */
    private const FLAT_EXTENSION_PATTERN = '/^(urn:[^\s]*:User)[.:]([^\s].*)$/i';

    /**
     * @inheritDoc
     */
    public function normalize(string $resourceType, string $operation, array $payload): array
    {
        if (strtoupper(trim($operation)) === 'PATCH') {
            return $this->normalizePatch($resourceType, $payload);
        }

        // POST/PUT: un-flatten any flat extension keys carried at the top level.
        return $this->unflattenMap($payload);
    }

    /**
     * Rewrite the `Operations` list of a PatchOp body.
     *
     * @param string $resourceType
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizePatch(string $resourceType, array $payload): array
    {
        $key = array_key_exists('Operations', $payload)
            ? 'Operations'
            : (array_key_exists('operations', $payload) ? 'operations' : null);
        if ($key === null || !is_array($payload[$key]) || !array_is_list($payload[$key])) {
            return $payload;
        }

        $operations = [];
        foreach ($payload[$key] as $operation) {
            if (!is_array($operation)) {
                $operations[] = $operation;
                continue;
            }

            $expanded = $resourceType === self::RESOURCE_GROUP
                ? $this->expandMemberRemove($operation)
                : null;
            if ($expanded !== null) {
                foreach ($expanded as $rewritten) {
                    $operations[] = $rewritten;
                }
                continue;
            }

            $operations[] = $this->unflattenOperation($operation);
        }
        $payload[$key] = $operations;

        return $payload;
    }

    /**
     * Split an Entra group-member `remove` carrying members in `value` into one
     * `members[value eq "<id>"]` filtered remove per member.
     *
     * Returns null (leave the op untouched) when it is not a member-remove-by-value
     * or when a member id cannot be safely embedded in a filter — the core still
     * removes only the listed members in that case, never all.
     *
     * @param array<string,mixed> $operation
     * @return array<int,array<string,mixed>>|null
     */
    private function expandMemberRemove(array $operation): ?array
    {
        if (strtolower(trim((string)($operation['op'] ?? ''))) !== 'remove') {
            return null;
        }
        $path = isset($operation['path']) && is_string($operation['path']) ? strtolower(trim($operation['path'])) : '';
        if ($path !== 'members') {
            return null;
        }
        $value = $operation['value'] ?? null;
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return null;
        }

        $ops = [];
        foreach ($value as $member) {
            $id = is_array($member) ? ($member['value'] ?? null) : $member;
            // Only scalar ids can be embedded; a `"` would break the filter — bail
            // out entirely so the whole op is left for the core to handle by value.
            if (!is_string($id) && !is_int($id)) {
                return null;
            }
            $id = (string)$id;
            if ($id === '' || str_contains($id, '"')) {
                return null;
            }
            $ops[] = ['op' => 'remove', 'path' => sprintf('members[value eq "%s"]', $id)];
        }

        return $ops;
    }

    /**
     * Un-flatten a flat extension complex-attribute in a single PATCH operation.
     *
     * Handles the `path` form (`{path: "urn:…:User:department", value: v}` →
     * `{path: "urn:…:User", value: {department: v}}`) and the path-less form
     * (whose `value` is an attribute→value map with flat keys). Anything else is
     * returned unchanged.
     *
     * @param array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function unflattenOperation(array $operation): array
    {
        $path = isset($operation['path']) && is_string($operation['path']) ? trim($operation['path']) : '';

        if ($path !== '' && array_key_exists('value', $operation)
            && preg_match(self::FLAT_EXTENSION_PATTERN, $path, $m) === 1) {
            $operation['path'] = $m[1];
            $operation['value'] = [$m[2] => $operation['value']];

            return $operation;
        }

        if ($path === '' && isset($operation['value']) && is_array($operation['value'])
            && !array_is_list($operation['value'])) {
            $operation['value'] = $this->unflattenMap($operation['value']);
        }

        return $operation;
    }

    /**
     * Nest any flat extension keys of an attribute→value map.
     *
     * `{"urn:…:User:department": v}` → `{"urn:…:User": {department: v}}`, merging
     * into an existing nested object for the same schema. Non-flat keys pass
     * through. Total: a non-map value or unrecognized key is left as-is.
     *
     * @param array<string,mixed> $map
     * @return array<string,mixed>
     */
    private function unflattenMap(array $map): array
    {
        if (array_is_list($map)) {
            return $map;
        }

        $result = [];
        foreach ($map as $key => $value) {
            if (is_string($key) && preg_match(self::FLAT_EXTENSION_PATTERN, $key, $m) === 1) {
                $schema = $m[1];
                $existing = $result[$schema] ?? [];
                if (!is_array($existing) || array_is_list($existing)) {
                    $existing = [];
                }
                $existing[$m[2]] = $value;
                $result[$schema] = $existing;
                continue;
            }
            // A non-flat key (e.g. an already-nested extension object) for a schema
            // that flat keys already seeded: merge rather than overwrite so neither
            // side's sub-attributes are lost, regardless of iteration order.
            if (isset($result[$key]) && is_array($result[$key]) && !array_is_list($result[$key])
                && is_array($value) && !array_is_list($value)) {
                $result[$key] = $value + $result[$key];
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
