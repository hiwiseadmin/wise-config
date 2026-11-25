<?php
declare(strict_types=1);



use PHPUnit\Framework\TestCase;
use wise\config\model\ConfigModel;
use wise\config\model\ConfigHistoryModel;

/**
 * 模型属性测试
 *
 * 验证模型的基本属性配置（表名、时间戳、字段列表等）。
 * 注意：不执行数据库操作，仅测试模型元数据。
 */
class ConfigModelTest extends TestCase
{
    public function testConfigModelTableName()
    {
        $model = new ConfigModel();
        $this->assertEquals('config', $model->getName());
    }

    public function testConfigModelPk()
    {
        $model = new ConfigModel();
        $this->assertEquals('id', $model->getPk());
    }

    public function testConfigModelAutoWriteTimestamp()
    {
        $model = new ConfigModel();

        $ref = new \ReflectionClass($model);
        $prop = $ref->getProperty('autoWriteTimestamp');
        $prop->setAccessible(true);

        $this->assertTrue($prop->getValue($model));
    }

    public function testConfigModelCreateTimeField()
    {
        $model = new ConfigModel();
        $ref = new \ReflectionClass($model);
        $prop = $ref->getProperty('createTime');
        $prop->setAccessible(true);
        $this->assertEquals('create_time', $prop->getValue($model));
    }

    public function testConfigModelUpdateTimeField()
    {
        $model = new ConfigModel();
        $ref = new \ReflectionClass($model);
        $prop = $ref->getProperty('updateTime');
        $prop->setAccessible(true);
        $this->assertEquals('update_time', $prop->getValue($model));
    }

    public function testConfigHistoryModelTableName()
    {
        $model = new ConfigHistoryModel();
        $this->assertEquals('config_history', $model->getName());
    }

    public function testConfigHistoryModelHasConfigRelation()
    {
        $model = new ConfigHistoryModel();
        $ref = new \ReflectionClass($model);
        $this->assertTrue($ref->hasMethod('config'));
    }

    public function testConfigModelHasSearchMethod()
    {
        $model = new ConfigModel();
        $ref = new \ReflectionClass($model);
        $this->assertTrue($ref->hasMethod('search'));
    }
}
