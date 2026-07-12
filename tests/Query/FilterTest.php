<?php

declare(strict_types=1);

namespace FmsOData\Tests\Query;

use FmsOData\Query\Filter;
use FmsOData\Query\FilterFactory;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    public function testEqOperator(): void
    {
        $f = new FilterFactory();
        self::assertSame("name eq 'John'", (string) $f->eq('name', 'John'));
    }

    public function testNeOperator(): void
    {
        $f = new FilterFactory();
        self::assertSame("status ne 'active'", (string) $f->ne('status', 'active'));
    }

    public function testGtOperator(): void
    {
        $f = new FilterFactory();
        self::assertSame('age gt 18', (string) $f->gt('age', 18));
    }

    public function testGeOperator(): void
    {
        $f = new FilterFactory();
        self::assertSame('age ge 21', (string) $f->ge('age', 21));
    }

    public function testLtOperator(): void
    {
        $f = new FilterFactory();
        self::assertSame('age lt 65', (string) $f->lt('age', 65));
    }

    public function testLeOperator(): void
    {
        $f = new FilterFactory();
        self::assertSame('age le 65', (string) $f->le('age', 65));
    }

    public function testStartsWith(): void
    {
        $f = new FilterFactory();
        self::assertSame("startswith(name,'J')", (string) $f->startsWith('name', 'J'));
    }

    public function testEndsWith(): void
    {
        $f = new FilterFactory();
        self::assertSame("endswith(email,'@example.com')", (string) $f->endsWith('email', '@example.com'));
    }

    public function testContains(): void
    {
        $f = new FilterFactory();
        self::assertSame("contains(name,'ohn')", (string) $f->contains('name', 'ohn'));
    }

    public function testApostropheEscaping(): void
    {
        $f = new FilterFactory();
        self::assertSame("name eq 'O''Brien'", (string) $f->eq('name', "O'Brien"));
    }

    public function testBooleanTrue(): void
    {
        $f = new FilterFactory();
        self::assertSame('active eq true', (string) $f->eq('active', true));
    }

    public function testBooleanFalse(): void
    {
        $f = new FilterFactory();
        self::assertSame('active eq false', (string) $f->eq('active', false));
    }

    public function testNullLiteral(): void
    {
        $f = new FilterFactory();
        self::assertSame('deleted eq null', (string) $f->eq('deleted', null));
    }

    public function testIntegerLiteral(): void
    {
        $f = new FilterFactory();
        self::assertSame('count eq 42', (string) $f->eq('count', 42));
    }

    public function testFiniteFloat(): void
    {
        $f = new FilterFactory();
        self::assertSame('price gt 19.99', (string) $f->gt('price', 19.99));
    }

    public function testNonFiniteFloatRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $f = new FilterFactory();
        $f->eq('price', \NAN);
    }

    public function testInfinityRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $f = new FilterFactory();
        $f->eq('price', \INF);
    }

    public function testDateTimeLiteral(): void
    {
        $f = new FilterFactory();
        $dt = new \DateTimeImmutable('2026-04-17T14:45:30+00:00');
        self::assertSame("created gt 2026-04-17T14:45:30Z", (string) $f->gt('created', $dt));
    }

    public function testRawFilter(): void
    {
        $f = new FilterFactory();
        self::assertSame('custom expression', (string) $f->raw('custom expression'));
    }

    public function testChainedAnd(): void
    {
        $f = new FilterFactory();
        $filter = $f->eq('city', 'Barcelona')->and($f->gt('balance', 0));
        self::assertSame("(city eq 'Barcelona') and (balance gt 0)", (string) $filter);
    }

    public function testChainedOr(): void
    {
        $f = new FilterFactory();
        $filter = $f->eq('city', 'Barcelona')->or($f->eq('city', 'Madrid'));
        self::assertSame("(city eq 'Barcelona') or (city eq 'Madrid')", (string) $filter);
    }

    public function testChainedNot(): void
    {
        $f = new FilterFactory();
        $filter = $f->eq('city', 'Barcelona')->not();
        self::assertSame("not (city eq 'Barcelona')", (string) $filter);
    }

    public function testFactoryAnd(): void
    {
        $f = new FilterFactory();
        $filter = $f->and($f->eq('a', 1), $f->eq('b', 2));
        self::assertSame("(a eq 1) and (b eq 2)", (string) $filter);
    }

    public function testFactoryOr(): void
    {
        $f = new FilterFactory();
        $filter = $f->or($f->eq('a', 1), $f->eq('b', 2));
        self::assertSame("(a eq 1) or (b eq 2)", (string) $filter);
    }

    public function testFactoryNot(): void
    {
        $f = new FilterFactory();
        $filter = $f->not($f->eq('a', 1));
        self::assertSame("not (a eq 1)", (string) $filter);
    }

    public function testFilterCoerceWithFilter(): void
    {
        self::assertSame('x eq 1', Filter::coerce(new Filter('x eq 1')));
    }

    public function testFilterCoerceWithString(): void
    {
        self::assertSame('x eq 1', Filter::coerce('x eq 1'));
    }
}
