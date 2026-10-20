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

    // ==================== resolve() 基本测试 ====================

    public function testResolveExplicit()
    {
        $this->resolver->setExplicitNamespace('test_ns');
        $result = $this->resolver->resolve();

        $this->assertEquals('test_ns', $result);
    }

    public function testResolveExplicitOverridesAll()
    {
        // 显式指定应该覆盖请求上下文和默认值
        $this->resolver->setExplicitNamespace('explicit_overrides');
        $result = $this->resolver->resolve();

        $this->assertEquals('explicit_overrides', $result);
    }

    public function testResolveDefault()
    {
        // 无显式指定，无请求上下文 → 返回配置默认值 'default'
        $result = $this->resolver->resolve();

        $this->assertEquals('default', $result);
    }

    // ==================== 缓存测试 ====================

    public function testResolveCachedInRequest()
    {
        // 第一次调用：触发解析，缓存结果
        $first = $this->resolver->resolve();
        // 第二次调用：应返回缓存值，不重复解析
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

    // ==================== 缓存清除测试 ====================

    public function testSetExplicitClearsCache()
    {
        // 先解析一次，建立缓存
        $this->resolver->resolve();

        // 设置显式 namespace 应清除缓存
        $this->resolver->setExplicitNamespace('new_ns');
        $result = $this->resolver->resolve();

        $this->assertEquals('new_ns', $result);
    }

    public function testSetExplicitNullClearsCache()
    {
        // 先设显式值
        $this->resolver->setExplicitNamespace('temp_ns');
        $this->assertEquals('temp_ns', $this->resolver->resolve());

        // 清除显式指定，回到默认
        $this->resolver->setExplicitNamespace(null);
        $result = $this->resolver->resolve();

        $this->assertEquals('default', $result);
    }

    // ==================== getNamespace() 测试 ====================

    public function testGetNamespaceReturnsSameAsResolve()
    {
        $this->resolver->setExplicitNamespace('via_getter');
        $ns = $this->resolver->getNamespace();

        $this->assertEquals('via_getter', $ns);
        $this->assertEquals($this->resolver->resolve(), $ns);
    }

    // ==================== 链式调用测试 ====================

    public function testSetExplicitNamespaceReturnsSelf()
    {
        $returned = $this->resolver->setExplicitNamespace('chain_test');

        $this->assertSame($this->resolver, $returned);
    }
}
