<?php
declare(strict_types=1);



use PHPUnit\Framework\TestCase;
use wise\config\event\ConfigSaving;
use wise\config\event\ConfigSaved;
use wise\config\event\ConfigDeleting;
use wise\config\event\ConfigReverted;

/**
 * 事件系统测试
 *
 * 验证 4 个事件类的构造、属性赋值、类型一致性。
 */
class EventTest extends TestCase
{
    public function testConfigSavingEvent()
    {
        $event = new ConfigSaving('site_name', 'new_value', 'text', 'system', ['extra' => true]);

        $this->assertEquals('site_name', $event->key);
        $this->assertEquals('new_value', $event->value);
        $this->assertEquals('text', $event->type);
        $this->assertEquals('system', $event->group);
        $this->assertEquals(['extra' => true], $event->config);
        // namespace 默认值
        $this->assertEquals('default', $event->namespace);
    }

    /**
     * ConfigSaving 事件携带 namespace 字段
     */
    public function testConfigSavingHasNamespace()
    {
        $event = new ConfigSaving('key1', 'val1', 'text', 'mail', [], 'project_a');

        $this->assertEquals('project_a', $event->namespace);
        $this->assertNotEmpty($event->namespace);
    }

    public function testConfigSavingNamespaceDefaultsToDefault()
    {
        $event = new ConfigSaving('key1', 'val1', 'text', 'system');

        $this->assertEquals('default', $event->namespace);
        $this->assertNotEmpty($event->namespace);
    }

    public function testConfigSavedEvent()
    {
        $event = new ConfigSaved('site_name', 'old_val', 'new_val', 'text', 'mail');

        $this->assertEquals('site_name', $event->key);
        $this->assertEquals('old_val', $event->oldValue);
        $this->assertEquals('new_val', $event->newValue);
        $this->assertEquals('text', $event->type);
        $this->assertEquals('mail', $event->group);
        // namespace 默认值
        $this->assertEquals('default', $event->namespace);
    }

    /**
     * ConfigSaved 事件携带 namespace 字段
     */
    public function testConfigSavedHasNamespace()
    {
        $event = new ConfigSaved('key1', 'old', 'new', 'text', 'mail', 'project_b');

        $this->assertEquals('project_b', $event->namespace);
        $this->assertNotEmpty($event->namespace);
    }

    public function testConfigDeletingEvent()
    {
        $event = new ConfigDeleting('old_key', 42, 'system');

        $this->assertEquals('old_key', $event->key);
        $this->assertEquals(42, $event->configId);
        $this->assertEquals('system', $event->group);
        // namespace 默认值
        $this->assertEquals('default', $event->namespace);
    }

    public function testConfigDeletingWithNamespace()
    {
        $event = new ConfigDeleting('key_ns', 10, 'mail', 'custom_ns');

        $this->assertEquals('custom_ns', $event->namespace);
        $this->assertNotEmpty($event->namespace);
    }

    public function testConfigRevertedEvent()
    {
        $event = new ConfigReverted('site_name', 'before_revert', 'after_revert', 99);

        $this->assertEquals('site_name', $event->key);
        $this->assertEquals('before_revert', $event->fromValue);
        $this->assertEquals('after_revert', $event->toValue);
        $this->assertEquals(99, $event->historyId);
        // namespace 默认值
        $this->assertEquals('default', $event->namespace);
    }

    public function testConfigRevertedWithNamespace()
    {
        $event = new ConfigReverted('key_rv', 'old', 'new', 5, 'ns_reverted');

        $this->assertEquals('ns_reverted', $event->namespace);
        $this->assertNotEmpty($event->namespace);
    }
}
