<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Shared\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CanonicalJsonTest extends TestCase
{
    public function test_object_key_order_is_irrelevant_but_list_order_and_scalar_types_are_preserved(): void
    {
        $this->assertSame(CanonicalJson::encode(['b' => 2, 'a' => ['z' => false, 'x' => 1]]), CanonicalJson::encode(['a' => ['x' => 1, 'z' => false], 'b' => 2]));
        $this->assertNotSame(CanonicalJson::encode([1, 2]), CanonicalJson::encode([2, 1]));
        $this->assertNotSame(CanonicalJson::encode(['amount' => 1]), CanonicalJson::encode(['amount' => '1']));
        $this->assertNotSame(CanonicalJson::encode(['camera' => null]), CanonicalJson::encode(['camera' => false]));
    }

    public function test_inexact_floats_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CanonicalJson::encode(['amount' => 1.01]);
    }

    public function test_objects_require_an_explicit_safe_representation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CanonicalJson::encode(['unsafe' => new \stdClass]);
    }
}
