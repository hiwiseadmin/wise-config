<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use wise\config\resolver\PathResolver;

/**
 * 路径解析器单元测试
 *
 * 验证冒号路径的三段解析规则和 namespace 显式指定判断。
 */
class PathResolverTest extends TestCase
{
    /** @var PathResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PathResolver();
    }

    // ==================== parse() 测试 ====================

    public function testParseThreeSegments()
    {
        $result = $this->resolver->parse('project_a:system:site_name', 'default');

        $this->assertIsArray($result);
        $this->assertEquals('project_a', $result['namespace']);
        $this->assertEquals('system', $result['group']);
        $this->assertEquals('site_name', $result['key']);
    }

    public function testParseThreeSegmentsWithDots()
    {
        $result = $this->resolver->parse('project_a.sub:mail:host', 'default');

        $this->assertEquals('project_a.sub', $result['namespace']);
        $this->assertEquals('mail', $result['group']);
        $this->assertEquals('host', $result['key']);
    }

    public function testParseTwoSegments()
    {
        $result = $this->resolver->parse('mail:host', 'current');

        $this->assertIsArray($result);
        $this->assertEquals('current', $result['namespace']);
        $this->assertEquals('mail', $result['group']);
        $this->assertEquals('host', $result['key']);
    }

    public function testParseTwoSegmentsWithDefaultNs()
    {
        $result = $this->resolver->parse('seo:title', 'default');

        $this->assertEquals('default', $result['namespace']);
        $this->assertEquals('seo', $result['group']);
        $this->assertEquals('title', $result['key']);
    }

    public function testParseOneSegment()
    {
        $result = $this->resolver->parse('site_name', 'current');

        $this->assertIsArray($result);
        $this->assertEquals('current', $result['namespace']);
        $this->assertEquals('system', $result['group']);
        $this->assertEquals('site_name', $result['key']);
    }

    public function testParseOneSegmentWithDefaultNs()
    {
        $result = $this->resolver->parse('description', 'default');

        $this->assertEquals('default', $result['namespace']);
        $this->assertEquals('system', $result['group']);
        $this->assertEquals('description', $result['key']);
    }

    // ==================== hasExplicitNamespace() 测试 ====================

    public function testHasExplicitNamespaceTrueWithThreeSegments()
    {
        $this->assertTrue($this->resolver->hasExplicitNamespace('a:b:c'));
    }

    public function testHasExplicitNamespaceTrueWithDottedNamespace()
    {
        $this->assertTrue($this->resolver->hasExplicitNamespace('project_a.sub:v2:key'));
    }

    public function testHasExplicitNamespaceFalseWithTwoSegments()
    {
        $this->assertFalse($this->resolver->hasExplicitNamespace('a:b'));
    }

    public function testHasExplicitNamespaceFalseWithOneSegment()
    {
        $this->assertFalse($this->resolver->hasExplicitNamespace('a'));
    }

    public function testHasExplicitNamespaceFalseWithEmptyString()
    {
        $this->assertFalse($this->resolver->hasExplicitNamespace(''));
    }

    // ==================== 边界情况测试 ====================

    public function testParseThreeSegmentsPreservesCurrentNs()
    {
        // 三段格式中 currentNs 参数在返回值中被路径中的值覆盖
        $result = $this->resolver->parse('ns1:grp1:key1', 'should_be_ignored');

        $this->assertEquals('ns1', $result['namespace']);
        $this->assertNotEquals('should_be_ignored', $result['namespace']);
    }

    public function testParseWithColonInValue()
    {
        // 只有前两个冒号被分割，剩余内容作为 key
        $result = $this->resolver->parse('ns:grp:key:with:colons', 'default');

        $this->assertEquals('ns', $result['namespace']);
        $this->assertEquals('grp', $result['group']);
        $this->assertEquals('key:with:colons', $result['key']);
    }
}
