<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScimAzure\Test\Unit\Integration;

use DmLab\AdminScim\Api\RequestNormalizerInterface;
use DmLab\AdminScimAzure\Model\Normalization\AzureRequestNormalizer;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Entra's default-mapping PatchOp bodies (`Test/Unit/_files/entra/`), normalized and checked
 * against {@see CoreAcceptedPatchGrammar}.
 */
class EntraPayloadContractTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../_files/entra';

    /**
     * @return array<string,array{string}>
     */
    public static function fixtureProvider(): array
    {
        $cases = [];
        foreach (glob(self::FIXTURES . '/*.json') ?: [] as $file) {
            $cases[basename($file, '.json')] = [basename($file, '.json')];
        }
        ksort($cases);

        return $cases;
    }

    public function testEveryDocumentedFixtureIsPresent(): void
    {
        self::assertSame(
            [
                'group-member-add',
                'group-member-remove',
                'group-rename',
                'user-deactivate',
                'user-deactivate-batched',
                'user-email-change',
                'user-enterprise-attrs',
                'user-profile-update',
            ],
            array_keys(self::fixtureProvider())
        );
    }

    #[DataProvider('fixtureProvider')]
    public function testEveryNormalizedOpIsAcceptedByTheCore(string $fixture): void
    {
        $operations = $this->normalizedOperations($fixture);
        self::assertNotEmpty($operations);

        foreach ($operations as $op) {
            if (str_starts_with($fixture, 'group-')) {
                CoreAcceptedPatchGrammar::assertGroupOpAccepted($op);
                continue;
            }
            self::assertContains(
                CoreAcceptedPatchGrammar::classifyUserOp($op),
                [CoreAcceptedPatchGrammar::MAPPED, CoreAcceptedPatchGrammar::IGNORED]
            );
        }
    }

    // --- Intent ------------------------------------------------------------

    public function testBatchedDeactivateKeepsItsActiveOpAndRejectsNothing(): void
    {
        $active = [];
        foreach ($this->normalizedOperations('user-deactivate-batched') as $op) {
            $class = CoreAcceptedPatchGrammar::classifyUserOp($op);
            if (($op['path'] ?? null) === 'active') {
                self::assertSame(CoreAcceptedPatchGrammar::MAPPED, $class);
                $active[] = $op['value'];
                continue;
            }
            self::assertContains($class, [CoreAcceptedPatchGrammar::MAPPED, CoreAcceptedPatchGrammar::IGNORED]);
        }

        self::assertSame(['False'], $active);
    }

    public function testWorkEmailChangeIsMapped(): void
    {
        $operations = $this->normalizedOperations('user-email-change');

        self::assertSame('emails[type eq "work"].value', $operations[0]['path']);
        self::assertSame(CoreAcceptedPatchGrammar::MAPPED, CoreAcceptedPatchGrammar::classifyUserOp($operations[0]));
    }

    public function testEnterpriseAttributesAreAllIgnored(): void
    {
        foreach ($this->normalizedOperations('user-enterprise-attrs') as $op) {
            self::assertSame(CoreAcceptedPatchGrammar::IGNORED, CoreAcceptedPatchGrammar::classifyUserOp($op));
        }
    }

    public function testProfileUpdateCoreUrnActiveComesOutBare(): void
    {
        $operations = $this->normalizedOperations('user-profile-update');
        $paths = array_column($operations, 'path');

        self::assertContains('active', $paths);
        foreach ($paths as $path) {
            self::assertStringStartsNotWith('urn:ietf:params:scim:schemas:core:', $path);
        }
        $active = $operations[array_search('active', $paths, true)];
        self::assertSame('True', $active['value']);
        self::assertSame(CoreAcceptedPatchGrammar::MAPPED, CoreAcceptedPatchGrammar::classifyUserOp($active));
    }

    public function testGroupMemberRemoveTargetsOnlyTheListedMembers(): void
    {
        $targeted = [];
        foreach ($this->normalizedOperations('group-member-remove') as $op) {
            self::assertSame('remove', strtolower($op['op']));
            self::assertNotSame('members', strtolower(trim((string)($op['path'] ?? ''))));
            self::assertSame(1, preg_match('/^members\[value eq "([^"]+)"\]$/', $op['path'], $m));
            $targeted[] = $m[1];
        }

        self::assertSame(['42', '77'], $targeted);
    }

    public function testEmptyGroupMemberRemoveIsAcceptedNoOp(): void
    {
        $operations = (new AzureRequestNormalizer())->normalize(
            RequestNormalizerInterface::RESOURCE_GROUP,
            'PATCH',
            ['Operations' => [['op' => 'Remove', 'path' => 'members', 'value' => []]]]
        )['Operations'];

        self::assertSame([['op' => 'add', 'path' => 'members', 'value' => []]], $operations);
        CoreAcceptedPatchGrammar::assertGroupOpAccepted($operations[0]);
    }

    // --- Grammar helper negatives -----------------------------------------

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function rejectedUserOpProvider(): array
    {
        return [
            'unbalanced bracket' => [['op' => 'Replace', 'path' => 'emails[type eq "work".value', 'value' => 'x']],
            'path-less list value' => [['op' => 'Replace', 'value' => [['displayName' => 'Jane']]]],
            'remove userName' => [['op' => 'Remove', 'path' => 'userName']],
            'remove active' => [['op' => 'Remove', 'path' => 'active']],
            'remove core-URN active' => [
                ['op' => 'Remove', 'path' => 'urn:ietf:params:scim:schemas:core:2.0:User:active'],
            ],
            'unknown op' => [['op' => 'Merge', 'path' => 'active', 'value' => 'False']],
            'missing value' => [['op' => 'Replace', 'path' => 'active']],
            'list op' => [[['op' => 'Replace', 'path' => 'active', 'value' => 'False']]],
            'blank userName' => [['op' => 'Replace', 'path' => 'userName', 'value' => ' ']],
            'userName over 40 chars' => [['op' => 'Replace', 'path' => 'userName', 'value' => str_repeat('a', 41)]],
            'blank pathless givenName' => [['op' => 'Replace', 'value' => ['name.givenName' => '']]],
            'non-string familyName' => [['op' => 'Replace', 'path' => 'name.familyName', 'value' => 7]],
        ];
    }

    /**
     * @param array<mixed> $op
     */
    #[DataProvider('rejectedUserOpProvider')]
    public function testGrammarRejectsMalformedUserOp(array $op): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage((string)json_encode($op, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        CoreAcceptedPatchGrammar::classifyUserOp($op);
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function rejectedGroupOpProvider(): array
    {
        return [
            'unknown op' => [['op' => 'Merge', 'path' => 'displayName', 'value' => 'Ops']],
            'path-less list value' => [['op' => 'Replace', 'value' => [['displayName' => 'Ops']]]],
            'blank displayName' => [['op' => 'Replace', 'path' => 'displayName', 'value' => '']],
            'non-numeric member' => [['op' => 'Add', 'path' => 'members', 'value' => [['value' => 'abc']]]],
            'zero member' => [['op' => 'Add', 'path' => 'members', 'value' => [['value' => '0']]]],
            'non-numeric filtered member' => [['op' => 'Remove', 'path' => 'members[value eq "abc"]']],
        ];
    }

    /**
     * @param array<mixed> $op
     */
    #[DataProvider('rejectedGroupOpProvider')]
    public function testGrammarRejectsMalformedGroupOp(array $op): void
    {
        $this->expectException(AssertionFailedError::class);

        CoreAcceptedPatchGrammar::assertGroupOpAccepted($op);
    }

    public function testGrammarClassifiesIgnoredAndMappedShapes(): void
    {
        $mapped = CoreAcceptedPatchGrammar::MAPPED;
        $ignored = CoreAcceptedPatchGrammar::IGNORED;
        $cases = [
            [$ignored, ['op' => 'Replace', 'path' => 'title', 'value' => 'CTO']],
            [$ignored, ['op' => 'Replace', 'path' => 'name.middleName', 'value' => 'Q']],
            [$ignored, ['op' => 'Remove', 'path' => 'phoneNumbers[type eq "work"]']],
            [$mapped, ['op' => 'add', 'path' => 'active', 'value' => false]],
            [$mapped, ['op' => 'Replace', 'path' => 'emails[primary eq true].value', 'value' => 'a@b.c']],
        ];
        foreach ($cases as [$expected, $op]) {
            self::assertSame($expected, CoreAcceptedPatchGrammar::classifyUserOp($op));
        }
    }

    /**
     * @param string $fixture
     * @return list<array<string,mixed>>
     */
    private function normalizedOperations(string $fixture): array
    {
        $payload = json_decode(
            (string)file_get_contents(self::FIXTURES . '/' . $fixture . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(['urn:ietf:params:scim:api:messages:2.0:PatchOp'], $payload['schemas']);

        $resource = str_starts_with($fixture, 'group-')
            ? RequestNormalizerInterface::RESOURCE_GROUP
            : RequestNormalizerInterface::RESOURCE_USER;
        $normalized = (new AzureRequestNormalizer())->normalize($resource, 'PATCH', $payload);

        return $normalized['Operations'];
    }
}
