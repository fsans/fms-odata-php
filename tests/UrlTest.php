<?php

declare(strict_types=1);

namespace FmsOData\Tests;

use FmsOData\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    public function testSpacesBecomePercent20(): void
    {
        self::assertSame("name%20eq%20'Joe'", Url::odataEncode("name eq 'Joe'"));
    }

    public function testCommasPreserved(): void
    {
        self::assertSame('ID,Name,Age', Url::odataEncode('ID,Name,Age'));
    }

    public function testDollarSignPreserved(): void
    {
        self::assertSame('$select=name', Url::odataEncode('$select=name'));
    }

    public function testEqualsPreserved(): void
    {
        self::assertSame('field=value', Url::odataEncode('field=value'));
    }

    public function testSemicolonPreserved(): void
    {
        self::assertSame('a;b', Url::odataEncode('a;b'));
    }

    public function testParenthesesPreserved(): void
    {
        self::assertSame('Orders($select=id)', Url::odataEncode('Orders($select=id)'));
    }

    public function testAmpersandEncoded(): void
    {
        self::assertSame('a%26b', Url::odataEncode('a&b'));
    }

    public function testApostrophesPreservedInFormattedValues(): void
    {
        self::assertSame("'O''Brien'", Url::odataEncode("'O''Brien'"));
    }

    public function testPathSegmentEncoding(): void
    {
        self::assertSame('My%20Table', Url::encodePathSegment('My Table'));
    }

    public function testPathSegmentWithSpecialChars(): void
    {
        self::assertSame('DB%20%26%20Co.', Url::encodePathSegment('DB & Co.'));
    }

    public function testEmptyQueryValuesSkipped(): void
    {
        $qs = Url::buildQueryString([
            ['$select', 'name'],
            ['$filter', ''],
            ['$top', 10],
        ]);
        self::assertSame('$select=name&$top=10', $qs);
    }

    public function testStringZeroRetained(): void
    {
        $qs = Url::buildQueryString([['$skip', '0']]);
        self::assertSame('$skip=0', $qs);
    }

    public function testNullValuesSkipped(): void
    {
        $qs = Url::buildQueryString([
            ['$select', 'name'],
            ['$filter', null],
        ]);
        self::assertSame('$select=name', $qs);
    }

    public function testParameterOrderPreserved(): void
    {
        $qs = Url::buildQueryString([
            ['$select', 'a,b'],
            ['$filter', "x eq 1"],
            ['$top', 5],
            ['$skip', 10],
            ['$count', 'true'],
        ]);
        self::assertSame('$select=a,b&$filter=x%20eq%201&$top=5&$skip=10&$count=true', $qs);
    }

    public function testDateTimeFormattingUtc(): void
    {
        $dt = new \DateTimeImmutable('2026-04-17T14:45:30+00:00');
        self::assertSame('2026-04-17T14:45:30Z', Url::formatDateTime($dt));
    }

    public function testDateTimeFormattingNoFractionalSeconds(): void
    {
        $dt = new \DateTimeImmutable('2026-04-17T14:45:30.123456+00:00');
        $formatted = Url::formatDateTime($dt);
        self::assertStringNotContainsString('.123', $formatted);
        self::assertSame('2026-04-17T14:45:30Z', $formatted);
    }

    public function testDateTimeFormattingWithOffset(): void
    {
        $dt = new \DateTimeImmutable('2026-04-17T14:45:30+02:00');
        self::assertSame('2026-04-17T14:45:30+02:00', Url::formatDateTime($dt));
    }

    public function testDateTimeParsingWithZ(): void
    {
        $dt = Url::parseDateTime('2026-04-17T14:45:30Z');
        self::assertSame('2026-04-17T14:45:30+00:00', $dt->format('Y-m-d\TH:i:sP'));
    }

    public function testDateTimeParsingWithFractionalSeconds(): void
    {
        $dt = Url::parseDateTime('2026-04-17T14:45:30.123Z');
        self::assertSame(2026, (int) $dt->format('Y'));
    }

    public function testDateTimeParsingWithOffset(): void
    {
        $dt = Url::parseDateTime('2026-04-17T14:45:30+02:00');
        self::assertSame('+02:00', $dt->format('P'));
    }

    public function testFormatKeyInteger(): void
    {
        self::assertSame('123', Url::formatKey(123));
    }

    public function testFormatKeyBooleanTrue(): void
    {
        self::assertSame('true', Url::formatKey(true));
    }

    public function testFormatKeyBooleanFalse(): void
    {
        self::assertSame('false', Url::formatKey(false));
    }

    public function testFormatKeyStringWithApostrophe(): void
    {
        self::assertSame("'O''B-1'", Url::formatKey("O'B-1"));
    }

    public function testFormatKeySimpleString(): void
    {
        self::assertSame("'ABC'", Url::formatKey('ABC'));
    }
}
