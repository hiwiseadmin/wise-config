<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use wise\config\resolver\InheritanceResolver;

/**
 * 层次继承链解析器单元测试
 *
 * 验证基于 . 分隔的 namespace 继承链生成和沿链查找逻辑。
 */
class InheritanceResolverTest extends TestCase
{
    /** @var InheritanceResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new InheritanceResolver();
    }

    // ==================== getChain() 测试 ====================

    public function testGetChainWithThreeLevels()
    {
        $chain = $this->resolver->getChain('a.b.c');

        $this->assertCount(4, $chain);
        $this->assertEquals(['a.b.c', 'a.b', 'a', 'default'], $chain);
    }

    public function testGetChainWithTwoLevels()
    {
        $chain = $this->resolver->getChain('project_a.plugin_mail');

        $this->assertCount(3, $chain);
        $this->assertEquals(['project_a.plugin_mail', 'project_a', 'default'], $chain);
    }

    public function testGetChainWithOneLevel()
    {
        $chain = $this->resolver->getChain('project_a');

        $this->assertCount(2, $chain);
        $this->assertEquals(['project_a', 'default'], $chain);
    }

    public function testGetChainSimple()
    {
        $chain = $this->resolver->getChain('default');

        $this->assertCount(1, $chain);
        $this->assertEquals(['default'], $chain);
    }

    public function testGetChainDefaultNotDuplicated()
    {
        // 'default' 不在链中时才会追加，这里本身就在链中
        $chain = $this->resolver->getChain('default');

        $this->assertEquals(['default'], $chain);
        // 确保没有重复的 'default'
        $this->assertCount(1, $chain);
    }

    public function testGetChainMaxDepth()
    {
        // 构造一个超过 5 层的 namespace（共 6 个点号层级）
        $deep = 'a.b.c.d.e.f.g';
        $chain = $this->resolver->getChain($deep);

        // max_depth=5 限制 while 循环生成 5 个 namespace + 末尾 'default' = 共 6 个
        $this->assertEquals(6, count($chain));
        // 应确保包含目标 namespace
        $this->assertEquals('a.b.c.d.e.f.g', $chain[0]);
        // 应确保以 'default' 结尾
        $this->assertEquals('default', end($chain));
        // 验证链的结构：逐级截断
        $this->assertEquals('a.b.c.d.e.f', $chain[1]);
        $this->assertEquals('a.b.c.d.e', $chain[2]);
        $this->assertEquals('a.b.c.d', $chain[3]);
        $this->assertEquals('a.b.c', $chain[4]);
    }

    // ==================== resolve() 测试 ====================

    public function testResolveFindsInTargetNamespace()
    {
        $configs = [
            ['namespace' => 'project_a', 'group' => 'mail', 'key' => 'host', 'value' => 'smtp.a.com', 'status' => 1, 'type' => 'text'],
        ];

        $result = $this->resolver->resolve($configs, 'project_a', 'mail', 'host');

        $this->assertNotNull($result);
        $this->assertEquals('smtp.a.com', $result['value']);
        $this->assertEquals('project_a', $result['source_ns']);
        $this->assertFalse($result['is_inherited']);
    }

    public function testResolveFindsInParentNamespace()
    {
        $configs = [
            ['namespace' => 'project_a', 'group' => 'mail', 'key' => 'host', 'value' => 'smtp.a.com', 'status' => 1, 'type' => 'text'],
        ];

        // 查询 child namespace，值从父级继承
        $result = $this->resolver->resolve($configs, 'project_a.child', 'mail', 'host');

        $this->assertNotNull($result);
        $this->assertEquals('smtp.a.com', $result['value']);
        $this->assertEquals('project_a', $result['source_ns']);
        $this->assertTrue($result['is_inherited']);
    }

    public function testResolveFindsInDefault()
    {
        $configs = [
            ['namespace' => 'default', 'group' => 'system', 'key' => 'site_name', 'value' => 'DefaultSite', 'status' => 1, 'type' => 'text'],
        ];

        $result = $this->resolver->resolve($configs, 'project_a.sub', 'system', 'site_name');

        $this->assertNotNull($result);
        $this->assertEquals('DefaultSite', $result['value']);
        $this->assertEquals('default', $result['source_ns']);
        $this->assertTrue($result['is_inherited']);
    }

    public function testResolveChildOverridesParent()
    {
        $configs = [
            ['namespace' => 'project_a', 'group' => 'mail', 'key' => 'host', 'value' => 'smtp.a.com', 'status' => 1, 'type' => 'text'],
            ['namespace' => 'project_a.child', 'group' => 'mail', 'key' => 'host', 'value' => 'smtp.child.com', 'status' => 1, 'type' => 'text'],
        ];

        // child 自己的值应该优先
        $result = $this->resolver->resolve($configs, 'project_a.child', 'mail', 'host');

        $this->assertNotNull($result);
        $this->assertEquals('smtp.child.com', $result['value']);
        $this->assertEquals('project_a.child', $result['source_ns']);
        $this->assertFalse($result['is_inherited']);
    }

    public function testResolveReturnsNullWhenNotFound()
    {
        $configs = [
            ['namespace' => 'other_ns', 'group' => 'mail', 'key' => 'host', 'value' => 'x', 'status' => 1, 'type' => 'text'],
        ];

        $result = $this->resolver->resolve($configs, 'project_a', 'mail', 'host');

        $this->assertNull($result);
    }

    public function testResolveSkipsDisabledConfigs()
    {
        $configs = [
            // status=0 应被跳过
            ['namespace' => 'project_a', 'group' => 'mail', 'key' => 'host', 'value' => 'disabled', 'status' => 0, 'type' => 'text'],
            ['namespace' => 'default', 'group' => 'mail', 'key' => 'host', 'value' => 'fallback', 'status' => 1, 'type' => 'text'],
        ];

        $result = $this->resolver->resolve($configs, 'project_a', 'mail', 'host');

        $this->assertNotNull($result);
        $this->assertEquals('fallback', $result['value']);
        $this->assertEquals('default', $result['source_ns']);
        $this->assertTrue($result['is_inherited']);
    }

    public function testResolveInheritFalseBlocksInheritance()
    {
        $configs = [
            // 目标 namespace 的配置，inherit=0 阻断继承
            ['namespace' => 'project_a.child', 'group' => 'mail', 'key' => 'host', 'value' => 'child_only', 'status' => 1, 'type' => 'text', 'inherit' => 0],
            // 父级有值但被阻断，不应被使用
            ['namespace' => 'project_a', 'group' => 'mail', 'key' => 'host', 'value' => 'parent_value', 'status' => 1, 'type' => 'text'],
        ];

        $result = $this->resolver->resolve($configs, 'project_a.child', 'mail', 'host');

        $this->assertNotNull($result);
        $this->assertEquals('child_only', $result['value']);
        $this->assertEquals('project_a.child', $result['source_ns']);
    }

    public function testResolveInheritFalseWithNoOwnValue()
    {
        // inherit=false 但目标 namespace 没有该 key 的配置
        // 这种情况下没有配置可阻断，仍会沿链向上查找
        $configs = [
            ['namespace' => 'default', 'group' => 'system', 'key' => 'title', 'value' => 'DefaultTitle', 'status' => 1, 'type' => 'text'],
        ];

        $result = $this->resolver->resolve($configs, 'project_a.child', 'system', 'title');

        $this->assertNotNull($result);
        $this->assertEquals('DefaultTitle', $result['value']);
        $this->assertEquals('default', $result['source_ns']);
        $this->assertTrue($result['is_inherited']);
    }

    // ==================== indexByNamespace() / findInIndex() 测试 ====================

    public function testFindInIndexMatchesExactly()
    {
        $configs = [
            ['namespace' => 'ns1', 'group' => 'g1', 'key' => 'k1', 'value' => 'v1'],
            ['namespace' => 'ns2', 'group' => 'g1', 'key' => 'k1', 'value' => 'v2'],
        ];

        $indexed = $this->invokeMethod($this->resolver, 'indexByNamespace', [$configs]);
        $found   = $this->invokeMethod($this->resolver, 'findInIndex', [$indexed, 'ns2', 'g1', 'k1']);

        $this->assertNotNull($found);
        $this->assertEquals('v2', $found['value']);
    }

    public function testFindInIndexReturnsNullOnMismatch()
    {
        $configs = [
            ['namespace' => 'ns1', 'group' => 'g1', 'key' => 'k1', 'value' => 'v1'],
        ];

        $indexed = $this->invokeMethod($this->resolver, 'indexByNamespace', [$configs]);
        $found   = $this->invokeMethod($this->resolver, 'findInIndex', [$indexed, 'ns1', 'g1', 'k2']);

        $this->assertNull($found);
    }

    // ==================== 继承阻断事件测试 ====================

    public function testInheritFalseTriggersBlockedEvent()
    {
        $configs = [
            ['namespace' => 'project_a.child', 'group' => 'mail', 'key' => 'host', 'value' => 'child', 'status' => 1, 'type' => 'text', 'inherit' => 0],
            ['namespace' => 'project_a', 'group' => 'mail', 'key' => 'host', 'value' => 'parent', 'status' => 1, 'type' => 'text'],
        ];

        // 宿主已安装 think/framework 时，tests/bootstrap.php 的 class_alias 守卫不会生效，
        // MockEvent 无法接管 Event 门面，故改用子类 spy 断言通知调用（与门面实现解耦）。
        $resolver = $this->createBlockedEventSpy();

        $result = $resolver->resolve($configs, 'project_a.child', 'mail', 'host');

        $this->assertNotNull($result);
        $this->assertEquals('child', $result['value']);

        $this->assertCount(1, $resolver->blocked);
        $this->assertEquals('host', $resolver->blocked[0]['key']);
        $this->assertEquals('project_a.child', $resolver->blocked[0]['namespace']);
        $this->assertEquals('project_a', $resolver->blocked[0]['parentNs']);
    }

    public function testNoBlockedEventWhenNoParentConfig()
    {
        $configs = [
            ['namespace' => 'project_a.child', 'group' => 'mail', 'key' => 'host', 'value' => 'child', 'status' => 1, 'type' => 'text', 'inherit' => 0],
        ];

        $resolver = $this->createBlockedEventSpy();

        $resolver->resolve($configs, 'project_a.child', 'mail', 'host');

        $this->assertCount(0, $resolver->blocked);
    }

    /**
     * 创建记录 fireInheritanceBlocked() 调用的解析器 spy
     *
     * @return InheritanceResolver
     */
    private function createBlockedEventSpy(): InheritanceResolver
    {
        return new class extends InheritanceResolver {
            /** @var array<int, array{key: string, namespace: string, parentNs: string}> */
            public array $blocked = [];

            protected function fireInheritanceBlocked(string $key, string $namespace, string $parentNs): void
            {
                $this->blocked[] = ['key' => $key, 'namespace' => $namespace, 'parentNs' => $parentNs];
            }
        };
    }

    // ==================== 全链路测试 ====================

    public function testGetChainThenResolveFullIntegration()
    {
        $chain = $this->resolver->getChain('project_a.plugin_mail.prod');

        // 构造 mock 数据覆盖链上所有 namespace
        $configs = [
            ['namespace' => 'project_a', 'group' => 'mail', 'key' => 'host', 'value' => 'smtp.a.com', 'status' => 1, 'type' => 'text'],
            ['namespace' => 'project_a.plugin_mail', 'group' => 'mail', 'key' => 'port', 'value' => '587', 'status' => 1, 'type' => 'number'],
            ['namespace' => 'default', 'group' => 'mail', 'key' => 'timeout', 'value' => '30', 'status' => 1, 'type' => 'number'],
        ];

        // host: 从 project_a 继承
        $host = $this->resolver->resolve($configs, 'project_a.plugin_mail.prod', 'mail', 'host');
        $this->assertEquals('smtp.a.com', $host['value']);
        $this->assertEquals('project_a', $host['source_ns']);
        $this->assertTrue($host['is_inherited']);

        // port: 从 project_a.plugin_mail 继承
        $port = $this->resolver->resolve($configs, 'project_a.plugin_mail.prod', 'mail', 'port');
        $this->assertEquals('587', $port['value']);
        $this->assertEquals('project_a.plugin_mail', $port['source_ns']);
        $this->assertTrue($port['is_inherited']);

        // timeout: 从 default 继承
        $timeout = $this->resolver->resolve($configs, 'project_a.plugin_mail.prod', 'mail', 'timeout');
        $this->assertEquals('30', $timeout['value']);
        $this->assertEquals('default', $timeout['source_ns']);
        $this->assertTrue($timeout['is_inherited']);

        // 验证 chain 结构
        $this->assertEquals('project_a.plugin_mail.prod', $chain[0]);
        $this->assertEquals('project_a.plugin_mail', $chain[1]);
        $this->assertEquals('project_a', $chain[2]);
        $this->assertEquals('default', $chain[3]);
    }

    // ==================== 辅助方法 ====================

    /**
     * 调用 protected/private 方法
     */
    private function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
