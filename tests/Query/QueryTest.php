<?php

declare(strict_types=1);

namespace FmsOData\Tests\Query;

use FmsOData\Http\HttpClient;
use FmsOData\Query\Filter;
use FmsOData\Query\FilterFactory;
use FmsOData\Query\Query;
use FmsOData\Spec\Query\SortDirection;
use FmsOData\Tests\Support\MockTransport;
use FmsOData\Tests\Support\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    private function makeQuery(string $entitySet = 'Contacts'): Query
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['value' => []]));
        $http = new HttpClient('Basic abc', $transport);

        return new Query($http, 'https://fms.example.com/fmi/odata/v4/DB', $entitySet);
    }

    public function testNoOptionsUrl(): void
    {
        $q = $this->makeQuery();
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts', $q->toUrl());
    }

    public function testEncodedEntitySetName(): void
    {
        $q = $this->makeQuery('My Table');
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/My%20Table', $q->toUrl());
    }

    public function testCumulativeSelect(): void
    {
        $q = $this->makeQuery();
        $q->select('name')->select('email', 'age');
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts?$select=name,email,age', $q->toUrl());
    }

    public function testFilterInput(): void
    {
        $f = new FilterFactory();
        $q = $this->makeQuery();
        $q->filter($f->eq('name', 'John'));
        self::assertSame("https://fms.example.com/fmi/odata/v4/DB/Contacts?\$filter=name%20eq%20'John'", $q->toUrl());
    }

    public function testRawStringFilterInput(): void
    {
        $q = $this->makeQuery();
        $q->filter("name eq 'John'");
        self::assertSame("https://fms.example.com/fmi/odata/v4/DB/Contacts?\$filter=name%20eq%20'John'", $q->toUrl());
    }

    public function testCallbackFilterInput(): void
    {
        $q = $this->makeQuery();
        $q->filter(fn (FilterFactory $f) => $f->eq('name', 'John'));
        self::assertSame("https://fms.example.com/fmi/odata/v4/DB/Contacts?\$filter=name%20eq%20'John'", $q->toUrl());
    }

    public function testConsecutiveFiltersAndTogether(): void
    {
        $q = $this->makeQuery();
        $q->filter("a eq 1")->filter("b eq 2");
        self::assertSame("https://fms.example.com/fmi/odata/v4/DB/Contacts?\$filter=(a%20eq%201)%20and%20(b%20eq%202)", $q->toUrl());
    }

    public function testOrWhereBehavior(): void
    {
        $q = $this->makeQuery();
        $q->filter("a eq 1")->orWhere("b eq 2");
        self::assertSame("https://fms.example.com/fmi/odata/v4/DB/Contacts?\$filter=(a%20eq%201)%20or%20(b%20eq%202)", $q->toUrl());
    }

    public function testOrWhereWithoutExistingFilter(): void
    {
        $q = $this->makeQuery();
        $q->orWhere("a eq 1");
        self::assertSame("https://fms.example.com/fmi/odata/v4/DB/Contacts?\$filter=a%20eq%201", $q->toUrl());
    }

    public function testCumulativeOrdering(): void
    {
        $q = $this->makeQuery();
        $q->orderBy('name')->orderBy('age', SortDirection::DESC);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts?$orderby=name,age%20desc', $q->toUrl());
    }

    public function testTopValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeQuery()->top(-1);
    }

    public function testSkipValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeQuery()->skip(-1);
    }

    public function testCountEnable(): void
    {
        $q = $this->makeQuery();
        $q->count(true);
        self::assertStringContainsString('$count=true', $q->toUrl());
    }

    public function testCountDisable(): void
    {
        $q = $this->makeQuery();
        $q->count(true)->count(false);
        self::assertStringNotContainsString('$count', $q->toUrl());
    }

    public function testNestedExpand(): void
    {
        $q = $this->makeQuery();
        $q->expand('Orders', fn (Query $inner) => $inner->select('id', 'total')->top(5));
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts?$expand=Orders($select=id,total;$top=5)', $q->toUrl());
    }

    public function testMultipleExpand(): void
    {
        $q = $this->makeQuery();
        $q->expand('Orders')->expand('Items');
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts?$expand=Orders,Items', $q->toUrl());
    }

    public function testLiteralCommasInSelect(): void
    {
        $q = $this->makeQuery();
        $q->select('ID', 'Name', 'Age');
        self::assertStringNotContainsString('%2C', $q->toUrl());
        self::assertStringContainsString('ID,Name,Age', $q->toUrl());
    }

    public function testLiteralCommasInOrderby(): void
    {
        $q = $this->makeQuery();
        $q->orderBy('name')->orderBy('age');
        self::assertStringNotContainsString('%2C', $q->toUrl());
    }

    public function testNoPlusInQueryUrls(): void
    {
        $q = $this->makeQuery();
        $q->filter("name eq 'John Doe'");
        self::assertStringNotContainsString('+', $q->toUrl());
    }

    public function testNoPercentEncodedCommasInExpand(): void
    {
        $q = $this->makeQuery();
        $q->expand('Orders', fn (Query $inner) => $inner->select('id', 'total'));
        self::assertStringNotContainsString('%2C', $q->toUrl());
    }

    public function testDeterministicOptionOrder(): void
    {
        $f = new FilterFactory();
        $q = $this->makeQuery();
        $q->count(true)->skip(10)->top(5)->orderBy('name')->expand('Orders')->filter($f->eq('a', 1))->select('id', 'name');
        $url = $q->toUrl();
        $posSelect = \strpos($url, '$select');
        $posFilter = \strpos($url, '$filter');
        $posExpand = \strpos($url, '$expand');
        $posOrderby = \strpos($url, '$orderby');
        $posTop = \strpos($url, '$top');
        $posSkip = \strpos($url, '$skip');
        $posCount = \strpos($url, '$count');
        self::assertLessThan($posFilter, $posSelect);
        self::assertLessThan($posExpand, $posFilter);
        self::assertLessThan($posOrderby, $posExpand);
        self::assertLessThan($posTop, $posOrderby);
        self::assertLessThan($posSkip, $posTop);
        self::assertLessThan($posCount, $posSkip);
    }

    public function testCompleteComposedUrl(): void
    {
        $f = new FilterFactory();
        $q = $this->makeQuery();
        $q->select('id', 'name')
            ->filter($f->eq('active', true))
            ->orderBy('name')
            ->top(10)
            ->skip(5)
            ->count(true);
        $expected = 'https://fms.example.com/fmi/odata/v4/DB/Contacts?$select=id,name&$filter=active%20eq%20true&$orderby=name&$top=10&$skip=5&$count=true';
        self::assertSame($expected, $q->toUrl());
    }

    public function testNestedExpandNoLeadingQuestionMark(): void
    {
        $q = $this->makeQuery();
        $q->expand('Orders', fn (Query $inner) => $inner->select('id'));
        $url = $q->toUrl();
        $expandValue = \parse_url($url, \PHP_URL_QUERY);
        self::assertNotNull($expandValue);
        self::assertIsString($expandValue);
        \parse_str($expandValue, $params);
        self::assertSame('Orders($select=id)', $params['$expand']);
    }

    public function testByKeyReturnsEntityRef(): void
    {
        $q = $this->makeQuery();
        $ref = $q->byKey(42);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts(42)', $ref->toUrl());
    }
}
