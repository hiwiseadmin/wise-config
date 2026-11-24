<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use wise\config\resolver\NamespaceResolver;

/**
 * 命名空间推断器单元测试
 *
 * 验证三级命名空间推断（显式 > 请求上下文 > 默认值）和请求级缓存。
 */
class NamespaceResolverTest extends TestCase
{
    /** @var NamespaceResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new NamespaceResolver();
    }


    public function testResolveExplicit()
    {
        $this->resolver->setExplicitNamespace('test_ns');
        $result = $this->resolver->resolve();

        $this->assertEquals('test_ns', $result);
    }

    public function testResolveExplicitOverridesAll()
    {
        $this->resolver->setExplicitNamespace('explicit_overrides');
        $result = $this->resolver->resolve();

        $this->assertEquals('explicit_overrides', $result);
    }

    public function testResolveDefault()
    {
        $result = $this->resolver->resolve();

        $this->assertEquals('default', $result);
    }


    public function testResolveCachedInRequest()
    {
        $first = $this->resolver->resolve();
        $second = $this->resolver->resolve();

        $this->assertEquals($first, $second);
        $this->assertEquals('default', $second);
    }

    public function testResolveReturnsConsistentValueAfterExplicitSet()
    {
        $this->resolver->setExplicitNamespace('ns_cached');
        $first = $this->resolver->resolve();
        $second = $this->resolver->resolve();

        $this->assertEquals('ns_cached', $first);
        $this->assertEquals($first, $second);
    }


    public function testSetExplicitClearsCache()
    {
        $this->resolver->resolve();

        $this->resolver->setExplicitNamespace('new_ns');
        $result = $this->resolver->resolve();

        $this->assertEquals('new_ns', $result);
    }

    public function testSetExplicitNullClearsCache()
    {
        $this->resolver->setExplicitNamespace('temp_ns');
        $this->assertEquals('temp_ns', $this->resolver->resolve());

        $this->resolver->setExplicitNamespace(null);
        $result = $this->resolver->resolve();

        $this->assertEquals('default', $result);
    }


    public function testGetNamespaceReturnsSameAsResolve()
    {
        $this->resolver->setExplicitNamespace('via_getter');
        $ns = $this->resolver->getNamespace();

        $this->assertEquals('via_getter', $ns);
        $this->assertEquals($this->resolver->resolve(), $ns);
    }


    public function testSetExplicitNamespaceReturnsSelf()
    {
        $returned = $this->resolver->setExplicitNamespace('chain_test');

        $this->assertSame($this->resolver, $returned);
    }
}
