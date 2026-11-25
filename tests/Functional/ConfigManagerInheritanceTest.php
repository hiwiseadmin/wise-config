<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use wise\config\ConfigManager;
use Tests\Support\Facade\MockCache;
use Tests\Support\Facade\MockEvent;

/**
 * ConfigManager 继承功能测试
 *
 * 验证 getResolved() 继承链查找、override、inherit=false 阻断。
 *
 * 注意：getResolved/getOverrideStatus 需要数据库查询 ConfigModel，
 * 在无 DB 环境下标记跳过。
 */
class ConfigManagerInheritanceTest extends TestCase
{
    /** @var ConfigManager */
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();
        MockCache::resetStore();
        MockEvent::reset();
        $this->manager = new ConfigManager();
    }


    public function testInheritanceResolverIsInjected()
    {
        $ref = new \ReflectionClass($this->manager);
        $prop = $ref->getProperty('inheritanceResolver');
        $prop->setAccessible(true);

        $this->assertInstanceOf(
            \wise\config\resolver\InheritanceResolver::class,
            $prop->getValue($this->manager)
        );
    }

    public function testTagManagerIsOptionalInConstructor()
    {
        $ref = new \ReflectionClass($this->manager);
        $prop = $ref->getProperty('tagManager');
        $prop->setAccessible(true);

        $tagManager = $prop->getValue($this->manager);
        if ($tagManager !== null) {
            $this->assertInstanceOf(\wise\config\manager\TagManager::class, $tagManager);
        }
    }


    public function testGetResolvedMethodExists()
    {
        $ref = new \ReflectionClass($this->manager);
        $this->assertTrue($ref->hasMethod('getResolved'));
    }

    public function testGetOverrideStatusMethodExists()
    {
        $ref = new \ReflectionClass($this->manager);
        $this->assertTrue($ref->hasMethod('getOverrideStatus'));
    }

    public function testResetMethodExists()
    {
        $ref = new \ReflectionClass($this->manager);
        $this->assertTrue($ref->hasMethod('reset'));
    }


    /**
     * base 设 host='a'，child 不设 → getResolved('child:mail:host') = 'a'
     */
    public function testGetResolvedInherited()
    {
        $this->markTestSkipped(
            'getResolved() requires database queries through ConfigModel. ' .
            'Test in integration environment with seeded test data.'
        );
    }

    /**
     * base 设 host='a'，child 设 host='b' → getResolved('child:mail:host') = 'b'
     */
    public function testGetResolvedOverridden()
    {
        $this->markTestSkipped(
            'getResolved() requires database queries through ConfigModel.'
        );
    }

    /**
     * base 设值，child 设 inherit=false → getResolved 返回 child 自己的值
     */
    public function testInheritFalseBlocks()
    {
        $this->markTestSkipped(
            'getResolved() requires database queries through ConfigModel.'
        );
    }

    /**
     * 验证 source_ns 和 is_inherited 标记
     */
    public function testGetOverrideStatus()
    {
        $this->markTestSkipped(
            'getOverrideStatus() requires database queries through ConfigModel.'
        );
    }

    /**
     * 继承关闭时 getResolved 退化为普通 get
     */
    public function testGetResolvedWhenInheritanceDisabled()
    {
        $this->markTestSkipped(
            'Requires config() override and database connection.'
        );
    }
}
