<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use wise\config\bridge\TpConfigBridge;
use wise\config\ConfigManager;
use Tests\Support\Facade\MockCache;
use Tests\Support\Facade\MockEvent;
use Tests\Support\Facade\MockConfig;

/**
 * TpConfigBridge 功能测试
 *
 * 验证 ConfigManager → think\Config 注入和移除功能。
 *
 * 注意：injectToConfig() 需要数据库查询 ConfigModel，
 * 在无 DB 环境下标记跳过。
 */
class TpConfigBridgeTest extends TestCase
{
    /** @var ConfigManager */
    private $manager;

    /** @var TpConfigBridge */
    private $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        MockCache::resetStore();
        MockEvent::reset();
        MockConfig::reset();

        $this->manager = new ConfigManager();
        $this->bridge = new TpConfigBridge($this->manager);
    }

    // ==================== 构造测试 ====================

    public function testConstructorAcceptsConfigManager()
    {
        $this->assertInstanceOf(TpConfigBridge::class, $this->bridge);
    }

    public function testConstructorInjectsManager()
    {
        $ref = new \ReflectionClass($this->bridge);
        $prop = $ref->getProperty('manager');
        $prop->setAccessible(true);

        $this->assertSame($this->manager, $prop->getValue($this->bridge));
    }

    // ==================== removeFromConfig / injectToConfig 测试 ====================

    /**
     * removeFromConfig 需要完整的 think\Config facade 容器支持。
     * 在单元测试环境中标记跳过。
     */
    public function testRemoveFromConfigClearsInjectedData()
    {
        $this->markTestSkipped(
            'removeFromConfig() requires think\Config facade with full ThinkPHP container. ' .
            'Test in integration environment.'
        );
    }

    // ==================== setNestedValue 测试（通过反射） ====================

    public function testSetNestedValueCreatesNestedStructure()
    {
        $data = [];
        $this->invokeMethod($this->bridge, 'setNestedValue', [&$data, ['wise', 'system', 'site_name'], 'MySite']);

        $this->assertArrayHasKey('wise', $data);
        $this->assertArrayHasKey('system', $data['wise']);
        $this->assertEquals('MySite', $data['wise']['system']['site_name']);
    }

    public function testSetNestedValueOverwritesLeaf()
    {
        $data = ['wise' => ['system' => ['site_name' => 'old']]];
        $this->invokeMethod($this->bridge, 'setNestedValue', [&$data, ['wise', 'system', 'site_name'], 'new']);

        $this->assertEquals('new', $data['wise']['system']['site_name']);
    }

    public function testSetNestedValuePreservesSiblings()
    {
        $data = ['wise' => ['system' => ['site_name' => 'MySite']]];
        $this->invokeMethod($this->bridge, 'setNestedValue', [&$data, ['wise', 'system', 'tagline'], 'Best Site']);

        $this->assertEquals('MySite', $data['wise']['system']['site_name']);
        $this->assertEquals('Best Site', $data['wise']['system']['tagline']);
    }

    // ==================== 数据库依赖测试（标记跳过） ====================

    /**
     * injectToConfig 将数据库配置注入到 think\Config
     */
    public function testInjectToConfig()
    {
        $this->markTestSkipped(
            'injectToConfig() requires database queries through ConfigModel. ' .
            'Test in integration environment with seeded data.'
        );
    }

    // ==================== 辅助方法 ====================

    private function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
