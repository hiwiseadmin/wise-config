<?php
/**
 * 创建 WiseConfig 配置表和配置历史表
 *
 * 使用 think-migration 扩展提供的 Migrator 基类。
 * 表前缀通过配置文件设置。
 */
use think\migration\Migrator;
use think\migration\db\Column;

class CreateConfigTable extends Migrator
{
    /**
     * 获取配置表名
     */
    protected function getTable(): string
    {
        return 'config';
    }

    public function up(): void
    {
        $tableName = $this->getTable();
        $table = $this->table($tableName, [
            'engine'    => 'InnoDB',
            'comment'   => '系统配置表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('namespace', 100)->setDefault('default')->setComment('命名空间（支持层级 a.b.c）'))
            ->addColumn(Column::string('key', 100)->setComment('配置键名'))
            ->addColumn(Column::text('value')->setNullable()->setComment('配置值'))
            ->addColumn(Column::string('type', 20)->setDefault('text')->setComment('数据类型: text,number,html,image,json'))
            ->addColumn(Column::string('group', 50)->setDefault('system')->setComment('配置分组'))
            ->addColumn(Column::text('tags')->setNullable()->setComment('标签（JSON 数组，冗余快照，权威数据源为 config_tag 表）'))
            ->addColumn(Column::tinyInteger('inherit')->setDefault(1)->setComment('是否允许继承: 1=允许 0=阻断'))
            ->addColumn(Column::string('title', 100)->setDefault('')->setComment('配置项标题'))
            ->addColumn(Column::string('description', 255)->setDefault('')->setComment('配置项描述'))
            ->addColumn(Column::text('validation_rules')->setNullable()->setComment('验证规则（JSON）'))
            ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序号'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态(1:启用 0:禁用)'))
            ->addColumn(Column::tinyInteger('visibility')->setDefault(1)->setComment('可见性: 1=公开(系统配置页展示) 0=私有(不展示)'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex('namespace')
            ->addIndex(['namespace', 'group'], ['name' => 'idx_namespace_group'])
            ->addIndex('visibility', ['name' => 'idx_visibility'])
            ->addIndex('group')
            ->addIndex('sort')
            ->addIndex('status')
            ->addIndex(['namespace', 'group', 'key'], ['unique' => true, 'name' => 'idx_namespace_group_key'])
            ->create();
    }

    public function down(): void
    {
        $tableName = $this->getTable();
        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
