<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use wise\config\manager\TagManager;
use wise\config\model\ConfigTagModel;

/**
 * 标签管理器单元测试
 *
 * 测试标签管理的核心逻辑：同步、查询、去重。
 * 注意：TagManager 依赖数据库操作（Db::transaction / ConfigModel / ConfigTagModel），
 * 这些在纯单元测试环境下无法直接执行，使用标记跳过。
 */
class TagManagerTest extends TestCase
{
    /** @var ConfigTagModel */
    private $mockTagModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockTagModel = $this->createMock(ConfigTagModel::class);
    }

    // ==================== 构造测试 ====================

    public function testConstructorAcceptsConfigTagModel()
    {
        $tagManager = new TagManager($this->mockTagModel);

        $this->assertInstanceOf(TagManager::class, $tagManager);
    }

    public function testConstructorInjectsDependency()
    {
        $customModel = $this->createMock(ConfigTagModel::class);
        $tagManager = new TagManager($customModel);

        // 验证依赖被正确注入（通过反射验证内部属性）
        $ref = new \ReflectionClass($tagManager);
        $prop = $ref->getProperty('tagModel');
        $prop->setAccessible(true);

        $this->assertSame($customModel, $prop->getValue($tagManager));
    }

    // ==================== 数据库依赖测试（标记跳过） ====================

    /**
     * sync() 后 getTags() 应返回一致的结果。
     * 需要完整的 ThinkPHP 容器和数据库连接，单元测试环境下标记跳过。
     */
    public function testSyncFollowedByGetTags()
    {
        $this->markTestSkipped(
            'TagManager::sync() and getTags() require full ThinkPHP container ' .
            '(Db::transaction, ConfigModel::find, ConfigTagModel::where). ' .
            'Test in functional/integration environment with database.'
        );
    }

    /**
     * findByTag() 应返回匹配标签的配置列表。
     * 需要完整的 ThinkPHP 容器和数据库连接。
     */
    public function testFindByTagReturnsConfigs()
    {
        $this->markTestSkipped(
            'TagManager::findByTag() requires database queries through ' .
            'ConfigTagModel and ConfigModel static methods. ' .
            'Test in functional/integration environment with database.'
        );
    }

    /**
     * findByTags() AND 逻辑：同时拥有所有标签的配置。
     * 需要完整的 ThinkPHP 容器和数据库连接。
     */
    public function testFindByTagsAnd()
    {
        $this->markTestSkipped(
            'TagManager::findByTags() requires database queries. ' .
            'Test in functional/integration environment with database.'
        );
    }

    /**
     * findByTags() OR 逻辑：拥有任一标签的配置。
     * 需要完整的 ThinkPHP 容器和数据库连接。
     */
    public function testFindByTagsOr()
    {
        $this->markTestSkipped(
            'TagManager::findByTags() requires database queries. ' .
            'Test in functional/integration environment with database.'
        );
    }

    /**
     * 重复添加相同标签不应报错（去重逻辑）。
     * attach() 内部通过 array_diff 跳过已存在的标签。
     * 需要完整的 ThinkPHP 容器和数据库连接。
     */
    public function testAttachDeduplication()
    {
        $this->markTestSkipped(
            'TagManager::attach() requires Db::transaction, ConfigModel::find, ' .
            'and ConfigTagModel queries. ' .
            'The deduplication logic (array_diff) is verified in integration tests.'
        );
    }

    // ==================== 逻辑测试（无需数据库） ====================

    public function testSetConfigTagsWithEmptyArray()
    {
        $tagManager = new TagManager($this->mockTagModel);
        // setConfigTags 空数组时直接返回 true（不操作数据库）
        $result = $tagManager->setConfigTags(1, []);
        $this->assertTrue($result);
    }

    public function testFindByTagWithNamespaceFilter()
    {
        $this->markTestSkipped(
            'findByTag() requires database connection.'
        );
    }

    public function testFindByTagsExceedsMaxPerConfig()
    {
        $this->markTestSkipped(
            'findByTags() tag truncation requires database connection.'
        );
    }

    public function testFindByTagsWithEmptyArray()
    {
        $tagManager = new TagManager($this->mockTagModel);
        // 空标签时直接返回空数组（不操作数据库）
        $result = $tagManager->findByTags([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testAttachWithEmptyTags()
    {
        $tagManager = new TagManager($this->mockTagModel);
        // 空标签时 attach 返回 false
        $result = $tagManager->attach(1, []);
        $this->assertFalse($result);
    }

    public function testDetachWithEmptyTags()
    {
        $tagManager = new TagManager($this->mockTagModel);
        // 空标签时 detach 返回 false
        $result = $tagManager->detach(1, []);
        $this->assertFalse($result);
    }
}
