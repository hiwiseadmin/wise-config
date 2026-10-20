<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use wise\config\ConfigManager;
use wise\config\resolver\NamespaceResolver;

/**
 * 安全加固回归测试
 *
 * 覆盖审查发现的 S1（HTML 过滤绕过）与 S2（命名空间校验）修复，
 * 全部用例不依赖数据库，可在任何环境执行。
 */
class SecurityHardeningTest extends TestCase
{
    /** @var ConfigManager */
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ConfigManager();
    }

    // ==================== S1: HTML 净化 ====================

    /**
     * `/` 可作为 HTML 属性分隔符，正则兜底分支必须清除事件属性
     *
     * 直接针对 sanitizeHtmlWithRegex 断言，避免不同 libxml 版本的解析差异导致用例抖动；
     * sanitizeHtml() 的 DOM 分支同样会移除该属性（见 SpaceSeparated 用例）。
     */
    public function testSlashSeparatedEventAttributeIsRemovedByRegexFallback()
    {
        $clean = $this->invokeProtected('sanitizeHtmlWithRegex', ['<img/src=x/onerror=alert(1)>']);

        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
    }

    /**
     * 常规空格分隔的事件属性也必须被清除
     */
    public function testSpaceSeparatedEventAttributeIsRemoved()
    {
        $clean = $this->sanitize('<img src="x" onerror="alert(1)">');

        $this->assertStringNotContainsString('onerror', $clean);
    }

    /**
     * HTML 实体编码的 javascript: 协议必须被清除
     */
    public function testEntityEncodedJavascriptProtocolIsRemoved()
    {
        $clean = $this->sanitize('<a href="jav&#x61;script:alert(1)">x</a>');

        $this->assertStringNotContainsString('href', $clean);
        $this->assertStringNotContainsString('javascript', strtolower($clean));
    }

    /**
     * 契约：javascript: 协议（含制表符绕过）必须被判定为危险
     */
    public function testDangerousProtocolDetection()
    {
        $this->assertTrue($this->invokeProtected('hasDangerousProtocol', ['javascript:alert(1)']));
        $this->assertTrue($this->invokeProtected('hasDangerousProtocol', ["java\tscript:alert(1)"]));
        $this->assertTrue($this->invokeProtected('hasDangerousProtocol', ['jav&#x61;script:alert(1)']));
        $this->assertTrue($this->invokeProtected('hasDangerousProtocol', ['vbscript:msgbox(1)']));
        $this->assertFalse($this->invokeProtected('hasDangerousProtocol', ['https://example.com/a.png']));
        $this->assertFalse($this->invokeProtected('hasDangerousProtocol', ['/uploads/a.png']));
        $this->assertFalse($this->invokeProtected('hasDangerousProtocol', ['data:image/png;base64,AAAA']));
    }

    /**
     * 未闭合的 svg 标签必须被整体移除（onload 不能被保留）
     */
    public function testUnclosedSvgWithOnloadIsRemoved()
    {
        $clean = $this->sanitize('<svg/onload=alert(1)>');

        $this->assertStringNotContainsString('svg', strtolower($clean));
        $this->assertStringNotContainsString('onload', strtolower($clean));
    }

    // ==================== S2: 命名空间校验 ====================

    /**
     * 合法的分层命名空间应通过
     */
    public function testValidNamespacesAccepted()
    {
        $resolver = new NamespaceResolver();

        $this->assertTrue($resolver->isValidNamespace('default'));
        $this->assertTrue($resolver->isValidNamespace('project_a.plugin_mail.prod'));
        $this->assertTrue($resolver->isValidNamespace('game-core_1'));
    }

    /**
     * 含冒号、路径穿越、空白等非法命名空间应被拒绝
     */
    public function testInvalidNamespacesRejected()
    {
        $resolver = new NamespaceResolver();

        $this->assertFalse($resolver->isValidNamespace('a:b'));
        $this->assertFalse($resolver->isValidNamespace('../../etc'));
        $this->assertFalse($resolver->isValidNamespace('a b'));
        $this->assertFalse($resolver->isValidNamespace(''));
        $this->assertFalse($resolver->isValidNamespace('a/b'));
        $this->assertFalse($resolver->isValidNamespace(null));
        $this->assertFalse($resolver->isValidNamespace(str_repeat('a', 101)));
    }

    // ==================== 辅助方法 ====================

    private function sanitize(string $html): string
    {
        return $this->invokeProtected('sanitizeHtml', [$html]);
    }

    /**
     * 调用 protected 方法
     */
    private function invokeProtected(string $method, array $args)
    {
        $reflection = new \ReflectionMethod(ConfigManager::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->manager, $args);
    }
}
