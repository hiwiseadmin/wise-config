<?php
/**
 * 创建配置标签关联表 config_tags
 *
 * 使用 think-migration 扩展提供的 Migrator 基类。
 */
use think\migration\Migrator;
use think\migration\db\Column;

class CreateConfigTagsTable extends Migrator
{
    /**
     * 获取配置标签表名
     */
    protected function getTable(): string
    {
        return 'config_tag';
    }

    public function up(): void
    {
        $tableName = $this->getTable();
        $table = $this->table($tableName, [
            'engine'    => 'InnoDB',
            'comment'   => '配置标签关联表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::integer('config_id')->setUnsigned(true)->setComment('配置记录ID'))
            ->addColumn(Column::string('tag', 50)->setComment('标签值'))
            ->addColumn(Column::string('namespace', 100)->setDefault('default')->setComment('命名空间（冗余）'))
            ->addColumn(Column::dateTime('create_time')->setComment('创建时间'))
            ->addIndex(['config_id'], ['name' => 'idx_config_id'])
            ->addIndex(['tag'], ['name' => 'idx_tag'])
            ->addIndex(['namespace', 'tag'], ['name' => 'idx_namespace_tag'])
            ->addIndex(['config_id', 'tag'], ['unique' => true, 'name' => 'uk_config_tag'])
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
