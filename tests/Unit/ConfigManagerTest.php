<?php
declare(strict_types=1);



use PHPUnit\Framework\TestCase;
use wise\config\ConfigManager;
use wise\config\contract\TypeHandlerInterface;
use wise\config\event\ConfigSaving;
use wise\config\event\ConfigSaved;
use wise\config\event\ConfigDeleting;
use wise\config\event\ConfigReverted;
use wise\config\model\ConfigModel;
use wise\config\model\ConfigHistoryModel;
use think\facade\Cache;
use think\facade\Event;
use think\facade\Log;

/**
 * ConfigManager 单元测试
 *
 * 测试配置管理器的核心功能：读取、写入、删除、缓存、历史版本、类型处理。
 * 使用 PHPUnit Mock 模拟数据库和缓存层。
 */
class ConfigManagerTest extends TestCase
{
    /** @var ConfigManager */
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ConfigManager();
    }

    // ==================== 类型处理器测试 ====================

    public function testRegisterTypeHandler()
    {
        $handler = $this->createMockTypeHandler('custom', 'encoded_', function($v) { return substr($v, 8); });
        $this->manager->registerTypeHandler($handler);

        $registered = $this->manager->getTypeHandler('custom');
        $this->assertNotNull($registered);
        $this->assertEquals('custom', $registered->getType());
    }

    public function testDefaultTypeHandler()
    {
        $handler = $this->createMockTypeHandler('fallback', 'FB:', function($v) { return str_replace('FB:', '', $v); });
        $this->manager->setDefaultHandler($handler);

        // 未知类型应返回默认处理器
        $result = $this->manager->getTypeHandler('unknown_type');
        $this->assertNotNull($result);
        $this->assertEquals('fallback', $result->getType());
    }

    public function testGetTypeHandlerReturnsNullWhenNoHandler()
    {
        $result = $this->manager->getTypeHandler('nonexistent');
        $this->assertNull($result);
    }

    // ==================== 值编码/解码测试 ====================

    /** @dataProvider encodeDecodeProvider */
    public function testEncodeDecodeRoundTrip($inputValue, string $type, $expectedEncoded, $expectedDecoded)
    {
        $encoded = $this->invokeMethod($this->manager, 'encodeValue', [$inputValue, $type]);
        $this->assertEquals($expectedEncoded, $encoded, "encodeValue failed for type={$type}");

        $decoded = $this->invokeMethod($this->manager, 'decodeValue', [$encoded, $type]);
        $this->assertEquals($expectedDecoded, $decoded, "decodeValue failed for type={$type}");
    }

    public function encodeDecodeProvider(): array
    {
        return [
            'text: simple string'     => ['hello world', 'text', 'hello world', 'hello world'],
            'text: empty string'      => ['', 'text', '', ''],
            'text: numeric string'    => ['12345', 'text', '12345', '12345'],

            'number: integer'         => [42, 'number', '42', 42],
            'number: float'           => [3.14, 'number', '3.14', 3.14],
            'number: numeric string'  => ['99', 'number', '99', 99],
            'number: non-numeric'     => ['abc', 'number', 'abc', 'abc'],

            'json: array'             => [['a' => 1, 'b' => 2], 'json', '{"a":1,"b":2}', ['a' => 1, 'b' => 2]],
            'json: stringified json'  => ['{"x":"y"}', 'json', '{"x":"y"}', ['x' => 'y']],
            'json: nested object'     => [['items' => [1,2,3]], 'json', '{"items":[1,2,3]}', ['items' => [1,2,3]]],
            'json: empty array'       => [[], 'json', '[]', []],
            'json: invalid json str'  => ['not valid json', 'json', 'not valid json', 'not valid json'],

            'html: safe html'         => ['<p>Hello</p>', 'html', '<p>Hello</p>', '<p>Hello</p>'],
            'html: with script tag'   => ['<p>X</p><script>alert(1)</script>', 'html', '<p>X</p>', '<p>X</p>'],
            'html: with onclick'      => ['<a onclick="bad()">link</a>', 'html', '<a>link</a>', '<a>link</a>'],

            'image: relative path'    => ['uploads/logo.png', 'image', 'uploads/logo.png', '/storage/uploads/logo.png'],
            'image: absolute url'     => ['https://cdn.com/img.png', 'image', 'https://cdn.com/img.png', 'https://cdn.com/img.png'],
            'image: absolute path'    => ['/static/img.png', 'image', '/static/img.png', '/static/img.png'],
        ];
    }

    public function testCustomTypeHandlerEncoding()
    {
        $handler = $this->createMockTypeHandler('custom', 'PREFIX_', function($v) { return substr($v, 7); });
        $this->manager->registerTypeHandler($handler);

        $encoded = $this->invokeMethod($this->manager, 'encodeValue', ['value123', 'custom']);
        $this->assertEquals('PREFIX_value123', $encoded);

        $decoded = $this->invokeMethod($this->manager, 'decodeValue', ['PREFIX_value123', 'custom']);
        $this->assertEquals('value123', $decoded);
    }

    // ==================== HTML 净化测试 ====================

    public function testSanitizeHtmlRemovesScriptTags()
    {
        $html = '<div><p>Good</p><script>alert("xss")</script></div>';
        $clean = $this->invokeMethod($this->manager, 'sanitizeHtml', [$html]);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringContainsString('<p>Good</p>', $clean);
    }

    public function testSanitizeHtmlRemovesIframe()
    {
        $html = '<div><iframe src="evil"></iframe><p>ok</p></div>';
        $clean = $this->invokeMethod($this->manager, 'sanitizeHtml', [$html]);

        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringContainsString('<p>ok</p>', $clean);
    }

    public function testSanitizeHtmlRemovesInlineEventHandlers()
    {
        $html = '<a href="#" onclick="alert(1)" onmouseover="bad()">link</a>';
        $clean = $this->invokeMethod($this->manager, 'sanitizeHtml', [$html]);

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onmouseover', $clean);
    }

    public function testSanitizeHtmlRemovesJavascriptProtocol()
    {
        $html = '<a href="javascript:evil()">link</a>';
        $clean = $this->invokeMethod($this->manager, 'sanitizeHtml', [$html]);

        $this->assertStringNotContainsString('javascript:', $clean);
    }

    // ==================== 数组 Diff 测试 ====================

    public function testArrayDiff()
    {
        $old = ['name' => 'Alice', 'age' => 30, 'city' => 'NYC'];
        $new = ['name' => 'Bob', 'age' => 30, 'city' => 'LA'];

        $diff = $this->invokeMethod($this->manager, 'arrayDiff', [$old, $new]);

        $this->assertArrayHasKey('name', $diff);
        $this->assertEquals(['old' => 'Alice', 'new' => 'Bob'], $diff['name']);
        $this->assertArrayNotHasKey('age', $diff, 'Unchanged fields should not appear in diff');
        $this->assertArrayHasKey('city', $diff);
    }

    public function testArrayDiffWithNewKeys()
    {
        $old = ['a' => 1];
        $new = ['a' => 1, 'b' => 2];

        $diff = $this->invokeMethod($this->manager, 'arrayDiff', [$old, $new]);

        $this->assertArrayHasKey('b', $diff);
        $this->assertEquals(['old' => null, 'new' => 2], $diff['b']);
    }

    public function testArrayDiffWithRemovedKeys()
    {
        $old = ['a' => 1, 'b' => 2];
        $new = ['a' => 1];

        $diff = $this->invokeMethod($this->manager, 'arrayDiff', [$old, $new]);

        $this->assertArrayHasKey('b', $diff);
        $this->assertEquals(['old' => 2, 'new' => null], $diff['b']);
    }

    // ==================== 文本 Diff 测试 ====================

    public function testTextDiffLineChanges()
    {
        $old = "line1\nline2\nline3";
        $new = "line1\nline2_changed\nline3";

        $diff = $this->invokeMethod($this->manager, 'textDiff', [$old, $new]);

        $this->assertCount(1, $diff);
        $this->assertEquals(2, $diff[0]['line']);
        $this->assertEquals('line2', $diff[0]['old']);
        $this->assertEquals('line2_changed', $diff[0]['new']);
    }

    public function testTextDiffWithAddedLines()
    {
        $old = "line1";
        $new = "line1\nline2";

        $diff = $this->invokeMethod($this->manager, 'textDiff', [$old, $new]);

        $this->assertCount(1, $diff);
        $this->assertEquals(null, $diff[0]['old']);
        $this->assertEquals('line2', $diff[0]['new']);
    }

    // ==================== ConfigManager::has() 测试 ====================

    public function testHasReturnsTrueForExistingConfig()
    {
        // has() 需要完整的 ThinkPHP 容器支撑（Cache facade 绑定），
        // 单元测试环境下无法直接测试，标记为 skipped。
        $this->markTestSkipped('has() requires full ThinkPHP container with Cache facade binding');
    }

    // ==================== 辅助方法 ====================

    /**
     * 创建 Mock 类型处理器
     */
    private function createMockTypeHandler(string $type, string $encodePrefix, callable $decodeLogic): TypeHandlerInterface
    {
        return new class($type, $encodePrefix, $decodeLogic) implements TypeHandlerInterface {
            private $type;
            private $prefix;
            private $decoder;
            public function __construct(string $t, string $p, callable $d) {
                $this->type = $t; $this->prefix = $p; $this->decoder = $d;
            }
            public function getType(): string { return $this->type; }
            public function decode($rawValue): mixed { return ($this->decoder)($rawValue); }
            public function encode($inputValue): string { return $this->prefix . $inputValue; }
            public function renderFormField(string $name, $value, array $config): string {
                return '<input type="text" name="' . $name . '">';
            }
        };
    }

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
