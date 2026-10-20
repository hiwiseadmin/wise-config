<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use wise\config\ConfigManager;
use Tests\Support\Facade\MockCache;
use Tests\Support\Facade\MockEvent;

/**
 * ConfigManager Tag 功能测试
 *
 * 验证 addTag / removeTag / whereTags 的 API 接口。
 *
 * 注意：Tag 操作依赖 TagManager（可选组件）和数据库连接。
 * 在无 DB 环境下标记跳过。
 */
class ConfigManagerTagTest extends TestCase
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

    // ==================== API 签名验证 ====================

    public function testAddTagMethodExists()
    {
        $ref = new \ReflectionClass($this->manager);
        $this->assertTrue($ref->hasMethod('addTag'));
    }

    public function testRemoveTagMethodExists()
    {
        $ref = new \ReflectionClass($this->manager);
        $this->assertTrue($ref->hasMethod('removeTag'));
    }

    public function testWhereTagsMethodExists()
    {
        $ref = new \ReflectionClass($this->manager);
        $this->assertTrue($ref->hasMethod('whereTags'));
    }

    // ==================== 降级模式测试 ====================

    /**
     * 当 TagManager 未注册时（降级模式），addTag 应安全返回 false。
     */
    public function testAddTagReturnsFalseWhenTagManagerNotAvailable()
    {
        $ref = new \ReflectionClass($this->manager);
        $prop = $ref->getProperty('tagManager');
        $prop->setAccessible(true);

        if ($prop->getValue($this->manager) === null) {
            // TagManager 不可用，addTag 应返回 false
            $result = $this->manager->addTag('system:test', 'seo');
            $this->assertFalse($result);
        } else {
            $this->markTestSkipped('TagManager is registered; test with database instead.');
        }
    }

    /**
     * 当 TagManager 未注册时，removeTag 应安全返回 false。
     */
    public function testRemoveTagReturnsFalseWhenTagManagerNotAvailable()
    {
        $ref = new \ReflectionClass($this->manager);
        $prop = $ref->getProperty('tagManager');
        $prop->setAccessible(true);

        if ($prop->getValue($this->manager) === null) {
            $result = $this->manager->removeTag('system:test', 'seo');
            $this->assertFalse($result);
        } else {
            $this->markTestSkipped('TagManager is registered; test with database instead.');
        }
    }

    /**
     * 当 TagManager 未注册时，whereTags 应安全返回空数组。
     */
    public function testWhereTagsReturnsEmptyWhenTagManagerNotAvailable()
    {
        $ref = new \ReflectionClass($this->manager);
        $prop = $ref->getProperty('tagManager');
        $prop->setAccessible(true);

        if ($prop->getValue($this->manager) === null) {
            $result = $this->manager->whereTags(['seo']);
            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } else {
            $this->markTestSkipped('TagManager is registered; test with database instead.');
        }
    }

    // ==================== 数据库依赖测试（标记跳过） ====================

    /**
     * set(key, value, ['tags'=>['seo']]) 后 whereTags(['seo']) 能查回
     */
    public function testAddTagThenFindByTag()
    {
        $this->markTestSkipped(
            'Tag operations require TagManager with database connection. ' .
            'Test in integration environment with seeded data.'
        );
    }

    /**
     * 两个标签 AND 查询
     */
    public function testFindByTagsAnd()
    {
        $this->markTestSkipped(
            'whereTags() requires TagManager with database connection.'
        );
    }

    /**
     * 两个标签 OR 查询
     */
    public function testFindByTagsOr()
    {
        $this->markTestSkipped(
            'whereTags() requires TagManager with database connection.'
        );
    }
}
