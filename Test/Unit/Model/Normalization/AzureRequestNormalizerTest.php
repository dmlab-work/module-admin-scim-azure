<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScimAzure\Test\Unit\Model\Normalization;

use DmLab\AdminScim\Api\RequestNormalizerInterface;
use DmLab\AdminScimAzure\Model\Normalization\AzureRequestNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AzureRequestNormalizerTest extends TestCase
{
    private const ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /** @var AzureRequestNormalizer */
    private AzureRequestNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AzureRequestNormalizer();
    }

    public function testImplementsExtensionPointInterface(): void
    {
        self::assertInstanceOf(RequestNormalizerInterface::class, $this->normalizer);
    }

    // --- Deviation 1: flat complex-attribute PATCH -> nested ---------------

    public function testFlatExtensionPathIsUnflattenedToNestedValue(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [[
                'op' => 'add',
                'path' => self::ENTERPRISE . ':department',
                'value' => 'Engineering',
            ]]]
        );

        $op = $result['Operations'][0];
        self::assertSame(self::ENTERPRISE, $op['path']);
        self::assertSame(['department' => 'Engineering'], $op['value']);
    }

    public function testFlatExtensionPathWithDotSeparatorIsUnflattened(): void
    {
        // Entra also emits the schema URI joined to the attribute with a dot.
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [[
                'op' => 'replace',
                'path' => self::ENTERPRISE . '.costCenter',
                'value' => '4130',
            ]]]
        );

        $op = $result['Operations'][0];
        self::assertSame(self::ENTERPRISE, $op['path']);
        self::assertSame(['costCenter' => '4130'], $op['value']);
    }

    public function testPathlessFlatExtensionKeysAreNested(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [[
                'op' => 'add',
                'value' => [
                    self::ENTERPRISE . ':department' => 'Sales',
                    self::ENTERPRISE . ':costCenter' => '4130',
                    'displayName' => 'Jane Doe',
                ],
            ]]]
        );

        $value = $result['Operations'][0]['value'];
        self::assertSame(['department' => 'Sales', 'costCenter' => '4130'], $value[self::ENTERPRISE]);
        self::assertSame('Jane Doe', $value['displayName']);
    }

    public function testTopLevelFlatExtensionKeyIsNestedOnCreate(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'POST',
            [
                'userName' => 'jane.doe',
                self::ENTERPRISE . ':department' => 'Sales',
            ]
        );

        self::assertSame('jane.doe', $result['userName']);
        self::assertSame(['department' => 'Sales'], $result[self::ENTERPRISE]);
        self::assertArrayNotHasKey(self::ENTERPRISE . ':department', $result);
    }

    public function testFlatAndNestedKeysForSameSchemaMergeWithoutLoss(): void
    {
        // Flat key seeded first, then a nested object for the same schema: neither
        // side's sub-attributes may be dropped (regression guard for the overwrite).
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'POST',
            [
                'userName' => 'jane.doe',
                self::ENTERPRISE . ':department' => 'Sales',
                self::ENTERPRISE => ['title' => 'Manager'],
            ]
        );

        self::assertEqualsCanonicalizing(
            ['department' => 'Sales', 'title' => 'Manager'],
            $result[self::ENTERPRISE]
        );
    }

    public function testNestedAndFlatKeysForSameSchemaMergeRegardlessOfOrder(): void
    {
        // Same merge, opposite order (nested object first, then flat key).
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'POST',
            [
                'userName' => 'jane.doe',
                self::ENTERPRISE => ['title' => 'Manager'],
                self::ENTERPRISE . ':department' => 'Sales',
            ]
        );

        self::assertEqualsCanonicalizing(
            ['title' => 'Manager', 'department' => 'Sales'],
            $result[self::ENTERPRISE]
        );
    }

    public function testAlreadyNestedExtensionObjectPassesThrough(): void
    {
        $payload = [
            'userName' => 'jane.doe',
            self::ENTERPRISE => ['department' => 'Sales'],
        ];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'POST', $payload)
        );
    }

    // --- Deviation 3: ADD and REPLACE are equivalent ----------------------

    public function testAddAndReplaceOfSameAttributeProduceEquivalentValueShape(): void
    {
        $add = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [['op' => 'add', 'path' => self::ENTERPRISE . ':department', 'value' => 'Ops']]]
        );
        $replace = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [['op' => 'replace', 'path' => self::ENTERPRISE . ':department', 'value' => 'Ops']]]
        );

        // Same target path + value; only the op verb differs (the core treats
        // add/replace identically for single-valued attributes).
        self::assertSame($add['Operations'][0]['path'], $replace['Operations'][0]['path']);
        self::assertSame($add['Operations'][0]['value'], $replace['Operations'][0]['value']);
        self::assertSame('add', $add['Operations'][0]['op']);
        self::assertSame('replace', $replace['Operations'][0]['op']);
    }

    // --- Deviation 2: group-member remove via value -> path filter ---------

    public function testGroupMemberRemoveByValueIsRewrittenToPathFilter(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_GROUP,
            'PATCH',
            ['Operations' => [[
                'op' => 'remove',
                'path' => 'members',
                'value' => [['value' => '42']],
            ]]]
        );

        self::assertCount(1, $result['Operations']);
        self::assertSame(
            ['op' => 'remove', 'path' => 'members[value eq "42"]'],
            $result['Operations'][0]
        );
        // The unfiltered `path: members` — which naively strips ALL members — is gone.
        self::assertArrayNotHasKey('value', $result['Operations'][0]);
    }

    public function testGroupMemberRemoveWithMultipleMembersSplitsPerMember(): void
    {
        $result = $this->normalizer->normalize(
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
    }

    public function testGroupMemberRemoveAcceptsScalarMemberValues(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_GROUP,
            'PATCH',
            ['Operations' => [['op' => 'remove', 'path' => 'members', 'value' => ['42', 88]]]]
        );

        self::assertSame('members[value eq "42"]', $result['Operations'][0]['path']);
        self::assertSame('members[value eq "88"]', $result['Operations'][1]['path']);
    }

    public function testCapitalizedRemoveOpAndPathAreMatchedCaseInsensitively(): void
    {
        // Entra sends capitalized verbs/paths (`Remove`, `Members`); these must
        // still hit the per-member rewrite, never the whole-group strip.
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_GROUP,
            'PATCH',
            ['Operations' => [[
                'op' => 'Remove',
                'path' => 'Members',
                'value' => [['value' => '42']],
            ]]]
        );

        self::assertSame(
            ['op' => 'remove', 'path' => 'members[value eq "42"]'],
            $result['Operations'][0]
        );
    }

    public function testGroupMemberRemoveWithEmptyIdIsLeftForCoreToHandleByValue(): void
    {
        // An empty id can't be meaningfully embedded in a filter — bail out so the
        // core removes only the listed members (never all).
        $payload = ['Operations' => [[
            'op' => 'remove',
            'path' => 'members',
            'value' => [['value' => '']],
        ]]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', $payload)
        );
    }

    public function testGroupMemberRemoveWithUnsafeIdIsLeftForCoreToHandleByValue(): void
    {
        // A `"` in the id can't be embedded in the filter — leave the op intact so
        // the core removes only the listed members (never all).
        $payload = ['Operations' => [[
            'op' => 'remove',
            'path' => 'members',
            'value' => [['value' => 'a"b']],
        ]]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', $payload)
        );
    }

    public function testGroupMemberRemoveWithNonScalarMemberIdIsLeftForCoreToHandleByValue(): void
    {
        // A member whose `value` is itself a structure (not a scalar id) can't be
        // embedded in a filter — bail out entirely so the whole op is left for the
        // core, which removes only the listed members (never all).
        $payload = ['Operations' => [[
            'op' => 'remove',
            'path' => 'members',
            'value' => [['value' => ['nested' => 1]]],
        ]]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', $payload)
        );
    }

    public function testAlreadyFilteredGroupMemberRemovePassesThrough(): void
    {
        $payload = ['Operations' => [['op' => 'remove', 'path' => 'members[value eq "42"]']]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', $payload)
        );
    }

    public function testPathlessGroupMemberRemoveThatClearsAllIsNotExpanded(): void
    {
        // No `value` list to name members — a deliberate clear-all; leave it be.
        $payload = ['Operations' => [['op' => 'remove', 'path' => 'members']]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', $payload)
        );
    }

    public function testMemberRemoveByValueOnUserResourceIsNotTreatedAsGroupQuirk(): void
    {
        // The value->path rewrite is Group-scoped; a User PATCH is left untouched.
        $payload = ['Operations' => [['op' => 'remove', 'path' => 'members', 'value' => [['value' => '42']]]]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'PATCH', $payload)
        );
    }

    // --- Totality / pass-through ------------------------------------------

    public function testStandardUserPayloadPassesThroughUnchanged(): void
    {
        $payload = [
            'userName' => 'jane.doe',
            'active' => true,
            'emails' => [['value' => 'jane@example.com', 'primary' => true]],
        ];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'POST', $payload)
        );
    }

    public function testStandardGroupMemberAddPassesThroughUnchanged(): void
    {
        $payload = ['Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => '42']]]]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', $payload)
        );
    }

    public function testLowercaseOperationsKeyIsHandled(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_GROUP,
            'PATCH',
            ['operations' => [['op' => 'remove', 'path' => 'members', 'value' => [['value' => '42']]]]]
        );

        self::assertSame('members[value eq "42"]', $result['operations'][0]['path']);
    }

    public function testMalformedOperationsAreNotTouched(): void
    {
        $payload = ['Operations' => 'not-a-list'];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'PATCH', $payload)
        );
    }

    public function testNonArrayOperationEntriesSurvive(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => ['garbage', ['op' => 'add', 'path' => self::ENTERPRISE . ':department', 'value' => 'X']]]
        );

        self::assertSame('garbage', $result['Operations'][0]);
        self::assertSame(['department' => 'X'], $result['Operations'][1]['value']);
    }

    #[DataProvider('nonPatchProvider')]
    public function testNonPatchLeavesOperationsListAsDataUntouched(string $method): void
    {
        // Outside PATCH an `Operations` key is ordinary data, not ops — leave it.
        $payload = ['Operations' => [['op' => 'remove', 'path' => 'members', 'value' => [['value' => '42']]]]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_GROUP, $method, $payload)
        );
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function nonPatchProvider(): array
    {
        return ['post' => ['POST'], 'put' => ['PUT']];
    }
}
