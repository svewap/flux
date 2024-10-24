<?php
namespace FluidTYPO3\Flux\Tests\Unit\Attribute;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Attribute\DataTransformer;
use PHPUnit\Framework\TestCase;

class DataTransformerTest extends TestCase
{
    public function testSetsIdentifierInConstructor(): void
    {
        $subject = new DataTransformer('ident');
        self::assertSame('ident', $subject->identifier);
    }
}
