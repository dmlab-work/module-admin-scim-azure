<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScimAzure\Test\Unit\Integration;

use MageDevGroup\AdminScim\Api\RequestNormalizerInterface;
use MageDevGroup\AdminScim\Model\Normalization\RequestNormalizerChain;
use PHPUnit\Framework\TestCase;

/**
 * Task 2 acceptance: prove `admin-scim`'s extension-point seam already carries
 * everything an Entra normalizer needs, so no refinement in `admin-scim` is
 * required for the request-shaped deviations (flat complex attrs, ADD/REPLACE
 * inconsistency, group-member remove via `value`). The 204-response deviation is
 * not a request concern and is handled in Task 4.
 *
 * The proof drives the real {@see RequestNormalizerChain} with a spy normalizer:
 * it records exactly what the seam hands a normalizer and asserts the chain
 * returns the normalizer's rewrite verbatim. If the seam withheld any op field
 * (`op`/`path`/`value`) or flattened the Operations list, these would fail — which
 * is the signal to refine `admin-scim` rather than the Entra plugin.
 */
class SeamContractTest extends TestCase
{
    public function testSeamHandsNormalizerTheWholePatchOperationsList(): void
    {
        $spy = $this->spy();
        $chain = new RequestNormalizerChain([$spy]);

        // Entra flat complex-attribute PATCH: the attr is flattened onto the op path
        // instead of nested inside the schema-extension value object.
        $payload = [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [
                [
                    'op' => 'Add',
                    'path' => 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department',
                    'value' => 'Engineering',
                ],
                ['op' => 'Replace', 'path' => 'displayName', 'value' => 'Jane Doe'],
            ],
        ];

        $chain->apply(RequestNormalizerInterface::RESOURCE_USER, 'PATCH', $payload);

        // The seam must expose every op verbatim — including the flat path and the
        // raw `op` verb — so a normalizer can un-flatten and equate ADD/REPLACE.
        self::assertCount(1, $spy->calls);
        [$resourceType, $operation, $received] = $spy->calls[0];
        self::assertSame(RequestNormalizerInterface::RESOURCE_USER, $resourceType);
        self::assertSame('PATCH', $operation);
        self::assertSame($payload['Operations'], $received['Operations']);
        self::assertSame('Add', $received['Operations'][0]['op']);
        self::assertSame(
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department',
            $received['Operations'][0]['path']
        );
    }

    public function testSeamReturnsTheNormalizerRewriteVerbatim(): void
    {
        // A normalizer that un-flattens the enterprise `department` path into a
        // nested value object — the transform Task 3 will implement for real.
        $rewriter = new class () implements RequestNormalizerInterface {
            public function normalize(string $resourceType, string $operation, array $payload): array
            {
                $payload['Operations'][0] = [
                    'op' => 'replace',
                    'path' => 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User',
                    'value' => ['department' => $payload['Operations'][0]['value']],
                ];

                return $payload;
            }
        };
        $chain = new RequestNormalizerChain([$rewriter]);

        $result = $chain->apply(
            RequestNormalizerInterface::RESOURCE_USER,
            'PATCH',
            [
                'Operations' => [
                    [
                        'op' => 'add',
                        'path' => 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department',
                        'value' => 'Engineering',
                    ],
                ],
            ]
        );

        self::assertSame(
            [
                'department' => 'Engineering',
            ],
            $result['Operations'][0]['value']
        );
        self::assertSame('replace', $result['Operations'][0]['op']);
    }

    public function testSeamExposesGroupRemoveOpValueForValueToPathRewrite(): void
    {
        $spy = $this->spy();
        $chain = new RequestNormalizerChain([$spy]);

        // Entra's non-compliant group-member remove: the member sits in `value`,
        // not in a `path` filter. The seam must surface that `value` so a
        // normalizer can rewrite it to `members[value eq "42"]` (never strip all).
        $payload = [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [
                ['op' => 'Remove', 'path' => 'members', 'value' => [['value' => '42']]],
            ],
        ];

        $chain->apply(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', $payload);

        [$resourceType, , $received] = $spy->calls[0];
        self::assertSame(RequestNormalizerInterface::RESOURCE_GROUP, $resourceType);
        self::assertSame([['value' => '42']], $received['Operations'][0]['value']);
        self::assertSame('members', $received['Operations'][0]['path']);
    }

    /**
     * A recording normalizer that captures every call and passes the body through
     * unchanged — isolating what the seam *delivers* from any transform.
     */
    private function spy(): RequestNormalizerInterface
    {
        return new class () implements RequestNormalizerInterface {
            /** @var array<int,array{0:string,1:string,2:array<string,mixed>}> */
            public array $calls = [];

            public function normalize(string $resourceType, string $operation, array $payload): array
            {
                $this->calls[] = [$resourceType, $operation, $payload];

                return $payload;
            }
        };
    }
}
