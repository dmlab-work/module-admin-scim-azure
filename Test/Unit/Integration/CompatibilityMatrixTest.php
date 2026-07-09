<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScimAzure\Test\Unit\Integration;

use MageDevGroup\AdminScim\Api\RequestNormalizerInterface;
use MageDevGroup\AdminScim\Model\Normalization\RequestNormalizerChain;
use MageDevGroup\AdminScimAzure\Model\Normalization\AzureRequestNormalizer;
use MageDevGroup\AdminScimOkta\Model\Normalization\OktaRequestNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Task 5 acceptance — the provider-quirk compatibility matrix.
 *
 * Drives the whole admin-user provisioning lifecycle (create → read/filter →
 * update via flat PATCH → deactivate via `active:false` → group add/remove) with
 * stubbed, Entra-shaped SCIM request bodies through the REAL
 * {@see RequestNormalizerChain} carrying the {@see AzureRequestNormalizer} — the
 * exact wiring di.xml produces — asserting each documented Entra deviation is
 * absorbed end-to-end, not just in the normalizer under a microscope.
 *
 * It also pins the cross-provider contract: with the strict-RFC baseline provider
 * ({@see OktaRequestNormalizer}) sharing the chain, Okta-shaped and standard-RFC
 * requests keep flowing exactly as before — the Entra normalizer must never break
 * the Okta baseline (the seam is un-refined, so this must hold by construction).
 */
class CompatibilityMatrixTest extends TestCase
{
    private const ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /** @var RequestNormalizerChain The Entra-only chain di.xml wires for a tenant provisioning through Entra. */
    private RequestNormalizerChain $entra;

    protected function setUp(): void
    {
        $this->entra = new RequestNormalizerChain([new AzureRequestNormalizer()]);
    }

    // --- Entra lifecycle: each deviation absorbed through the real chain -------

    public function testCreateUnflattensEntraFlatEnterpriseAttribute(): void
    {
        // Entra POSTs the new admin user with the enterprise sub-attribute
        // flattened onto the top-level key instead of nested in the schema object.
        $created = $this->entra->apply(
            RequestNormalizerInterface::RESOURCE_USER,
            'POST',
            [
                'schemas' => [
                    'urn:ietf:params:scim:schemas:core:2.0:User',
                    self::ENTERPRISE,
                ],
                'userName' => 'jane.doe@contoso.com',
                'active' => true,
                self::ENTERPRISE . ':department' => 'Engineering',
            ]
        );

        // Deviation 1 absorbed: the flat key is nested under the schema URI.
        self::assertSame(['department' => 'Engineering'], $created[self::ENTERPRISE]);
        self::assertArrayNotHasKey(self::ENTERPRISE . ':department', $created);
        self::assertSame('jane.doe@contoso.com', $created['userName']);
    }

    public function testReadAndFilterCarryNoBodySoNothingIsNormalized(): void
    {
        // Entra matches an existing admin user with GET /Users?filter=userName eq
        // "…" — a read carries no request body, so the write-only normalizer chain
        // is never invoked. Model that as the empty body being returned verbatim.
        foreach (['POST', 'PUT', 'PATCH'] as $method) {
            self::assertSame(
                [],
                $this->entra->apply(RequestNormalizerInterface::RESOURCE_USER, $method, [])
            );
        }
    }

    public function testUpdateViaFlatPatchIsUnflattenedForBothAddAndReplace(): void
    {
        // Entra updates the enterprise attribute with a flat-path PATCH and uses
        // `add` and `replace` interchangeably (deviations 1 + 3).
        $add = $this->entra->apply(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [[
                'op' => 'add',
                'path' => self::ENTERPRISE . ':department',
                'value' => 'Platform',
            ]]]
        );
        $replace = $this->entra->apply(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [[
                'op' => 'replace',
                'path' => self::ENTERPRISE . ':department',
                'value' => 'Platform',
            ]]]
        );

        // Both land on the same nested target/value; only the verb differs, which
        // the core treats identically for single-valued attributes.
        self::assertSame(self::ENTERPRISE, $add['Operations'][0]['path']);
        self::assertSame(['department' => 'Platform'], $add['Operations'][0]['value']);
        self::assertSame($add['Operations'][0]['path'], $replace['Operations'][0]['path']);
        self::assertSame($add['Operations'][0]['value'], $replace['Operations'][0]['value']);
    }

    public function testDeactivateViaActiveFalsePassesThroughAsNativeBoolean(): void
    {
        // Entra deactivates with a native-boolean `active:false` REPLACE — already
        // strict-RFC, so the Entra normalizer leaves it untouched (the stringified
        // `active` quirk is Okta's, not Entra's).
        $deactivated = $this->entra->apply(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]
        );

        self::assertFalse($deactivated['Operations'][0]['value']);
        self::assertSame('active', $deactivated['Operations'][0]['path']);
    }

    public function testGroupMemberAddIsStandardAndPassesThrough(): void
    {
        // Entra's group-member ADD is compliant SCIM — nothing to absorb.
        $payload = ['Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => '42']]]]];

        self::assertSame(
            $payload,
            $this->entra->apply(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', $payload)
        );
    }

    public function testGroupMemberRemoveTouchesOnlyTheNamedMemberNeverAll(): void
    {
        // Entra's non-compliant remove: the member(s) sit in `value`, not a `path`
        // filter (deviation 2). Handled naively this strips the whole group; the
        // chain must rewrite it to a per-member `members[value eq "…"]` filter.
        $result = $this->entra->apply(
            RequestNormalizerInterface::RESOURCE_GROUP,
            'PATCH',
            ['Operations' => [[
                'op' => 'remove',
                'path' => 'members',
                'value' => [['value' => '42'], ['value' => '77']],
            ]]]
        );

        self::assertCount(2, $result['Operations']);
        self::assertSame('members[value eq "42"]', $result['Operations'][0]['path']);
        self::assertSame('members[value eq "77"]', $result['Operations'][1]['path']);
        // The unfiltered `path: members` (which removes ALL members) is gone.
        foreach ($result['Operations'] as $op) {
            self::assertArrayNotHasKey('value', $op);
        }
    }

    // --- Cross-provider: the Entra normalizer must not break the Okta baseline --

    public function testOktaBaselineSurvivesWhenEntraSharesTheChain(): void
    {
        // Both providers di-merged into one chain (Okta first, as declaration order
        // would place it). Okta's stringified-`active` deactivate must still coerce
        // to a native bool — the Entra normalizer leaves `active` alone.
        $chain = new RequestNormalizerChain([
            new OktaRequestNormalizer(),
            new AzureRequestNormalizer(),
        ]);

        $result = $chain->apply(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => 'false']]]
        );

        self::assertFalse($result['Operations'][0]['value']);
    }

    public function testEntraNormalizerLeavesOktaStyleStringifiedActiveUntouched(): void
    {
        // Entra alone must not corrupt an Okta-shaped body: it is not the Entra
        // deviation, so the string is passed straight through for the core (or a
        // co-registered Okta normalizer) to handle.
        $result = $this->entra->apply(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => 'false']]]
        );

        self::assertSame('false', $result['Operations'][0]['value']);
    }

    public function testStandardRfcRequestIsUntouchedByEitherProvider(): void
    {
        // A strict-RFC create must pass through a combined chain byte-for-byte —
        // neither provider claims it.
        $chain = new RequestNormalizerChain([
            new OktaRequestNormalizer(),
            new AzureRequestNormalizer(),
        ]);
        $payload = [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'userName' => 'jane.doe@contoso.com',
            'active' => true,
            'emails' => [['value' => 'jane@contoso.com', 'primary' => true]],
        ];

        self::assertSame(
            $payload,
            $chain->apply(RequestNormalizerInterface::RESOURCE_USER, 'POST', $payload)
        );
    }
}
