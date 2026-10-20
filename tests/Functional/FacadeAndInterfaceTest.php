<?php
declare(strict_types=1);



use PHPUnit\Framework\TestCase;
use wise\config\facade\WiseConfig;
use wise\config\contract\TypeHandlerInterface;

/**
 * 门面与接口测试
 */
class FacadeAndInterfaceTest extends TestCase
{
    public function testWiseConfigFacadeClass()
    {
        $ref = new \ReflectionClass(WiseConfig::class);

        // 门面类必须重载 getFacadeClass()
        $this->assertTrue($ref->hasMethod('getFacadeClass'));
    }

    public function testTypeHandlerInterfaceExists()
    {
        $this->assertTrue(interface_exists(TypeHandlerInterface::class));
    }

    public function testTypeHandlerInterfaceMethods()
    {
        $ref = new \ReflectionClass(TypeHandlerInterface::class);

        $this->assertTrue($ref->hasMethod('getType'));
        $this->assertTrue($ref->hasMethod('decode'));
        $this->assertTrue($ref->hasMethod('encode'));
        $this->assertTrue($ref->hasMethod('renderFormField'));
    }

    /**
     * 测试 TypeHandlerInterface 的实际实现
     */
    public function testTypeHandlerImplementation()
    {
        $handler = new class implements TypeHandlerInterface {
            public function getType(): string { return 'test_type'; }
            public function decode($rawValue): mixed { return strtoupper($rawValue); }
            public function encode($inputValue): string { return strtolower($inputValue); }
            public function renderFormField(string $name, $value, array $config): string {
                return '<input name="' . $name . '" value="' . $value . '">';
            }
        };

        $this->assertEquals('test_type', $handler->getType());
        $this->assertEquals('HELLO', $handler->decode('hello'));
        $this->assertEquals('hello', $handler->encode('HELLO'));
        $this->assertStringContainsString('input name="test"', $handler->renderFormField('test', 'val', []));
    }
}
