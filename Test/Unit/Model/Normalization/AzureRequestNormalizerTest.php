<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
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
    private const CORE = 'urn:ietf:params:scim:schemas:core:2.0:User';

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

    // --- Extension shapes pass through ------------------------------------

    #[DataProvider('enterprisePathProvider')]
    public function testEnterprisePathPassesThroughByteIdentical(string $op, string $path): void
    {
        $payload = ['Operations' => [['op' => $op, 'path' => $path, 'value' => 'Sales']]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'PATCH', $payload)
        );
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function enterprisePathProvider(): array
    {
        return [
            'colon' => ['Add', self::ENTERPRISE . ':department'],
            'dot' => ['Replace', self::ENTERPRISE . '.costCenter'],
            'bare urn' => ['replace', self::ENTERPRISE],
        ];
    }

    public function testEnterpriseRemoveWithoutValuePassesThrough(): void
    {
        $payload = ['Operations' => [['op' => 'Remove', 'path' => self::ENTERPRISE . ':manager']]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'PATCH', $payload)
        );
    }

    public function testPathlessEnterpriseKeysPassThrough(): void
    {
        $payload = ['Operations' => [[
            'op' => 'Replace',
            'value' => [
                self::ENTERPRISE . ':department' => 'Sales',
                self::ENTERPRISE => ['costCenter' => '4130'],
                'displayName' => 'Jane Doe',
            ],
        ]]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'PATCH', $payload)
        );
    }

    #[DataProvider('nonPatchProvider')]
    public function testTopLevelEnterpriseKeysPassThroughOnCreateAndReplace(string $method): void
    {
        $payload = [
            'userName' => 'jane.doe',
            self::ENTERPRISE . ':department' => 'Sales',
            self::ENTERPRISE => ['title' => 'Manager'],
        ];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, $method, $payload)
        );
    }

    // --- Core-URN-qualified User attributes -> bare -------------------------

    #[DataProvider('coreUrnPathProvider')]
    public function testCoreUrnPathIsStrippedToBareAttribute(string $path, string $expected): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [['op' => 'Replace', 'path' => $path, 'value' => 'True']]]
        );

        self::assertSame(['op' => 'Replace', 'path' => $expected, 'value' => 'True'], $result['Operations'][0]);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function coreUrnPathProvider(): array
    {
        return [
            'colon' => [self::CORE . ':active', 'active'],
            'dot' => [self::CORE . '.active', 'active'],
            'sub-attribute' => [self::CORE . ':name.givenName', 'name.givenName'],
            'filter + sub-attribute' => [self::CORE . ':emails[type eq "work"].value', 'emails[type eq "work"].value'],
            'mixed case' => ['URN:IETF:params:SCIM:schemas:Core:2.0:user:active', 'active'],
        ];
    }

    public function testPathlessCoreUrnKeysAreStripped(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [[
                'op' => 'Replace',
                'value' => [
                    self::CORE . ':active' => 'False',
                    'displayName' => 'Jane Doe',
                    self::ENTERPRISE . ':department' => 'Sales',
                ],
            ]]]
        );

        self::assertSame(
            ['active' => 'False', 'displayName' => 'Jane Doe', self::ENTERPRISE . ':department' => 'Sales'],
            $result['Operations'][0]['value']
        );
    }

    #[DataProvider('nonPatchProvider')]
    public function testTopLevelCoreUrnKeyIsStrippedOnCreateAndReplace(string $method): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            $method,
            ['userName' => 'jane.doe', self::CORE . '.active' => true]
        );

        self::assertSame(['userName' => 'jane.doe', 'active' => true], $result);
    }

    public function testBareCoreUrnPathWithBareKeysIsLeftForTheCore(): void
    {
        $payload = ['Operations' => [['op' => 'Replace', 'path' => self::CORE, 'value' => ['active' => 'False']]]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'PATCH', $payload)
        );
    }

    public function testBareCoreUrnPathHasItsObjectKeysStripped(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [[
                'op' => 'Replace',
                'path' => self::CORE,
                'value' => [self::CORE . '.active' => 'False', self::CORE . ':userName' => 'jane'],
            ]]]
        );

        self::assertSame(
            ['op' => 'Replace', 'path' => self::CORE, 'value' => ['active' => 'False', 'userName' => 'jane']],
            $result['Operations'][0]
        );
    }

    public function testCollidingKeysAreSplitIntoConsecutiveOpsInOrder(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [
                [
                    'op' => 'Replace',
                    'value' => [
                        self::CORE . ':name.givenName' => 'A',
                        'name' => ['givenName' => 'X', 'familyName' => 'Doe'],
                        'name.givenName' => 'B',
                    ],
                ],
                ['op' => 'Replace', 'path' => 'active', 'value' => false],
            ]]
        );

        self::assertSame(
            [
                [
                    'op' => 'Replace',
                    'value' => ['name.givenName' => 'A', 'name' => ['givenName' => 'X', 'familyName' => 'Doe']],
                ],
                ['op' => 'Replace', 'value' => ['name.givenName' => 'B']],
                ['op' => 'Replace', 'path' => 'active', 'value' => false],
            ],
            $result['Operations']
        );
    }

    public function testCollidingComplexValuesKeepBothPartialUpdates(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [[
                'op' => 'Replace',
                'path' => self::CORE,
                'value' => [
                    self::CORE . ':name' => ['givenName' => 'Jane'],
                    'name' => ['familyName' => 'Doe'],
                ],
            ]]]
        );

        self::assertSame(
            [
                ['op' => 'Replace', 'path' => self::CORE, 'value' => ['name' => ['givenName' => 'Jane']]],
                ['op' => 'Replace', 'path' => self::CORE, 'value' => ['name' => ['familyName' => 'Doe']]],
            ],
            $result['Operations']
        );
    }

    public function testCoreUrnPathOnGroupResourceIsUntouched(): void
    {
        $payload = ['Operations' => [['op' => 'Replace', 'path' => self::CORE . ':displayName', 'value' => 'Ops']]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', $payload)
        );
    }

    // --- Edge cases ---------------------------------------------------------

    public function testRemoveWithCoreUrnPathIsStripped(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [['op' => 'Remove', 'path' => self::CORE . ':title']]]
        );

        self::assertSame(['op' => 'Remove', 'path' => 'title'], $result['Operations'][0]);
    }

    public function testExtensionUrnContainingCoreIsUntouched(): void
    {
        $payload = ['Operations' => [
            [
                'op' => 'Replace',
                'path' => 'urn:ietf:params:scim:schemas:extension:core:2.0:User:department',
                'value' => 'X',
            ],
            ['op' => 'Replace', 'path' => 'urn:acme:core:2.0:User:active', 'value' => 'X'],
        ]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'PATCH', $payload)
        );
    }

    public function testNonStringPathIsUntouched(): void
    {
        $payload = ['Operations' => [['op' => 'Replace', 'path' => 42, 'value' => [self::CORE . ':active' => 'X']]]];

        self::assertSame(
            $payload,
            $this->normalizer->normalize(RequestNormalizerInterface::RESOURCE_USER, 'PATCH', $payload)
        );
    }

    public function testOperationsOrderIsPreserved(): void
    {
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            ['Operations' => [
                ['op' => 'Replace', 'path' => 'active', 'value' => 'False'],
                ['op' => 'Replace', 'path' => self::CORE . ':title', 'value' => 'Ex-Manager'],
                ['op' => 'Add', 'path' => self::ENTERPRISE . ':department', 'value' => 'Sales'],
                ['op' => 'Replace', 'path' => 'displayName', 'value' => 'Jane Doe'],
            ]]
        );

        self::assertSame(
            ['active', 'title', self::ENTERPRISE . ':department', 'displayName'],
            array_column($result['Operations'], 'path')
        );
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
        // core handles the listed members by value.
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
        // the core handles the listed members by value.
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
        // core, which handles the listed members by value.
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

    public function testGroupMemberRemoveWithEmptyValueListBecomesNoOpAdd(): void
    {
        // The core reads an empty list as "clear all"; Entra means "remove nobody".
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_GROUP,
            'PATCH',
            ['Operations' => [
                ['op' => 'Remove', 'path' => 'members', 'value' => []],
                ['op' => 'Replace', 'path' => 'displayName', 'value' => 'Ops'],
            ]]
        );

        self::assertSame(
            [
                ['op' => 'add', 'path' => 'members', 'value' => []],
                ['op' => 'Replace', 'path' => 'displayName', 'value' => 'Ops'],
            ],
            $result['Operations']
        );
    }

    public function testLoneEmptyGroupMemberRemoveKeepsOperationsNonEmpty(): void
    {
        // An empty Operations list is a 400 in the core; the no-op add keeps it a 200.
        $result = $this->normalizer->normalize(
            RequestNormalizerInterface::RESOURCE_GROUP,
            'PATCH',
            ['Operations' => [['op' => 'Remove', 'path' => 'members', 'value' => []]]]
        );

        self::assertSame([['op' => 'add', 'path' => 'members', 'value' => []]], $result['Operations']);
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
        self::assertSame(self::ENTERPRISE . ':department', $result['Operations'][1]['path']);
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
