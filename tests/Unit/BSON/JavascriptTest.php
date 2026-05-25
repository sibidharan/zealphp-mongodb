<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use JsonSerializable;
use PHPUnit\Framework\TestCase;
use Stringable;
use ZealPHP\MongoDB\BSON\Javascript;
use ZealPHP\MongoDB\BSON\JavascriptInterface;
use ZealPHP\MongoDB\BSON\Type;

class JavascriptTest extends TestCase
{
    public function testConstructWithoutScope(): void
    {
        $js = new Javascript('function() { return 1; }');
        $this->assertSame('function() { return 1; }', $js->getCode());
        $this->assertNull($js->getScope());
    }

    public function testConstructWithArrayScope(): void
    {
        $js = new Javascript('return x', ['x' => 10]);
        $this->assertSame('return x', $js->getCode());
        // Array scope should be converted to object
        $scope = $js->getScope();
        $this->assertIsObject($scope);
        $this->assertSame(10, $scope->x);
    }

    public function testConstructWithObjectScope(): void
    {
        $scopeObj = (object) ['y' => 'hello'];
        $js = new Javascript('return y', $scopeObj);
        $this->assertSame('return y', $js->getCode());
        $this->assertIsObject($js->getScope());
        $this->assertSame('hello', $js->getScope()->y);
    }

    public function testGetCode(): void
    {
        $code = 'var a = 1; return a;';
        $js = new Javascript($code);
        $this->assertSame($code, $js->getCode());
    }

    public function testGetScopeNull(): void
    {
        $js = new Javascript('return true');
        $this->assertNull($js->getScope());
    }

    public function testGetScopeObject(): void
    {
        $js = new Javascript('return n', ['n' => 42]);
        $scope = $js->getScope();
        $this->assertNotNull($scope);
        $this->assertIsObject($scope);
        $this->assertSame(42, $scope->n);
    }

    public function testToString(): void
    {
        $code = 'function() { return true; }';
        $js = new Javascript($code, ['x' => 1]);
        $this->assertSame($code, (string) $js);
    }

    public function testJsonSerializeWithoutScope(): void
    {
        $js = new Javascript('return 1');
        $result = $js->jsonSerialize();
        $this->assertSame(['$code' => 'return 1'], $result);
        $this->assertArrayNotHasKey('$scope', $result);
    }

    public function testJsonSerializeWithScope(): void
    {
        $js = new Javascript('return x', ['x' => 5]);
        $result = $js->jsonSerialize();
        $this->assertSame('return x', $result['$code']);
        $this->assertArrayHasKey('$scope', $result);
        $this->assertIsObject($result['$scope']);
        $this->assertSame(5, $result['$scope']->x);
    }

    public function testSetState(): void
    {
        $js = Javascript::__set_state(['code' => 'return z', 'scope' => ['z' => 99]]);
        $this->assertInstanceOf(Javascript::class, $js);
        $this->assertSame('return z', $js->getCode());
        $scope = $js->getScope();
        $this->assertIsObject($scope);
        $this->assertSame(99, $scope->z);
    }

    public function testSetStateWithoutScope(): void
    {
        $js = Javascript::__set_state(['code' => 'return 1']);
        $this->assertInstanceOf(Javascript::class, $js);
        $this->assertSame('return 1', $js->getCode());
        $this->assertNull($js->getScope());
    }

    public function testImplementsInterfaces(): void
    {
        $js = new Javascript('1');
        $this->assertInstanceOf(JavascriptInterface::class, $js);
        $this->assertInstanceOf(JsonSerializable::class, $js);
        $this->assertInstanceOf(Type::class, $js);
        $this->assertInstanceOf(Stringable::class, $js);
    }
}
