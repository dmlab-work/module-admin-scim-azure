<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScimAzure\Model\Normalization;

use DmLab\AdminScim\Api\RequestNormalizerInterface;

/**
 * Microsoft Entra ID provider-quirk normalizer for the `admin-scim` server.
 *
 * Canonicalizes two Entra shapes into the strict RFC form:
 *
 *  1. Core-URN-qualified User attributes — `urn:ietf:params:scim:schemas:core:2.0:User:active`
 *     (or `.active`) in a PATCH `path`, a path-less `value` key or a POST/PUT top-level key
 *     becomes the bare `active` (also inside the object of a `path` equal to the core URN).
 *  2. Group-member remove via `value` — `{op: remove, path: "members", value: [{value: "42"}]}`
 *     becomes one `members[value eq "42"]` filtered remove per member; an empty list becomes an
 *     empty `add`, a no-op the core accepts (it reads an empty remove as "clear all members").
 *
 * Extension paths and keys pass through untouched: the core accepts and ignores them, and
 * nesting them produced a shape the core rejected.
 *
 * Pure and total: any other payload is returned unchanged, and it never throws.
 */
class AzureRequestNormalizer implements RequestNormalizerInterface
{
    /**
     * Provider code: the di item key in `RequestNormalizerChain` `normalizers`. The core runs this
     * normalizer only when `dmlab_admin_scim/general/provider` equals it, or when it is the only
     * registered normalizer and no provider is selected.
     */
    public const PROVIDER_CODE = 'azure';

    private const CORE_USER_URN = 'urn:ietf:params:scim:schemas:core:2.0:User';

    /**
     * A core-URN-qualified User attribute: group 1 = the bare attribute path.
     */
    private const CORE_USER_ATTRIBUTE_PATTERN = '/^urn:ietf:params:scim:schemas:core:2\.0:User[:.](\S.*)$/i';

    /**
     * @inheritDoc
     */
    public function normalize(string $resourceType, string $operation, array $payload): array
    {
        if (strtoupper(trim($operation)) === 'PATCH') {
            return $this->normalizePatch($resourceType, $payload);
        }

        return $resourceType === self::RESOURCE_USER ? $this->stripCoreUrnKeys($payload) : $payload;
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

            if ($resourceType === self::RESOURCE_GROUP) {
                foreach ($this->expandMemberRemove($operation) ?? [$operation] as $rewritten) {
                    $operations[] = $rewritten;
                }
                continue;
            }

            $rewrittenOps = $resourceType === self::RESOURCE_USER
                ? $this->stripCoreUrnOperation($operation)
                : [$operation];
            foreach ($rewrittenOps as $rewritten) {
                $operations[] = $rewritten;
            }
        }
        $payload[$key] = $operations;

        return $payload;
    }

    /**
     * Split an Entra group-member `remove` carrying members in `value` into one
     * `members[value eq "<id>"]` filtered remove per member.
     *
     * Returns an empty `members` add for an empty `value` list: it removes nobody, and dropping the
     * op could leave `Operations` empty, which the core rejects. Returns null (leave
     * the op untouched) when it is not a member-remove-by-value or when a member id cannot be
     * safely embedded in a filter — the core still removes only the listed members then.
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
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        if ($value === []) {
            return [['op' => 'add', 'path' => 'members', 'value' => []]];
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
     * Strip the core User URN from a PATCH op's `path` or `value` map keys.
     *
     * The keys are stripped when the op is path-less or its `path` is the core URN itself. A key
     * that collides after stripping starts a new op, so every value still applies in its order.
     *
     * @param array<string,mixed> $operation
     * @return array<int,array<string,mixed>>
     */
    private function stripCoreUrnOperation(array $operation): array
    {
        $path = $operation['path'] ?? '';
        if (!is_string($path)) {
            return [$operation];
        }
        if (trim($path) !== '' && strcasecmp(trim($path), self::CORE_USER_URN) !== 0) {
            $operation['path'] = $this->bareCoreAttribute($path) ?? $path;

            return [$operation];
        }

        $value = $operation['value'] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            return [$operation];
        }

        $chunks = [[]];
        foreach ($value as $key => $subValue) {
            $key = is_string($key) ? $this->bareCoreAttribute($key) ?? $key : $key;
            if (array_key_exists($key, $chunks[array_key_last($chunks)])) {
                $chunks[] = [];
            }
            $chunks[array_key_last($chunks)][$key] = $subValue;
        }

        return array_map(static function (array $chunk) use ($operation): array {
            $operation['value'] = $chunk;

            return $operation;
        }, $chunks);
    }

    /**
     * Replace core-URN-qualified keys of an attribute→value map with the bare attribute.
     *
     * @param array<mixed> $map
     * @return array<mixed>
     */
    private function stripCoreUrnKeys(array $map): array
    {
        if (array_is_list($map)) {
            return $map;
        }

        $result = [];
        foreach ($map as $key => $value) {
            $key = is_string($key) ? $this->bareCoreAttribute($key) ?? $key : $key;
            // A repeated key moves to its last position, keeping the core's sequential last-wins order.
            unset($result[$key]);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * The bare attribute path of a core-URN-qualified User attribute, or null when it is not one.
     *
     * @param string $path
     * @return string|null
     */
    private function bareCoreAttribute(string $path): ?string
    {
        return preg_match(self::CORE_USER_ATTRIBUTE_PATTERN, trim($path), $m) === 1 ? $m[1] : null;
    }
}
