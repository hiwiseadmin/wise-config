<?php
/**
 * 创建 WiseConfig 配置历史表
 *
 * 使用 think-migration 扩展提供的 Migrator 基类。
 * 表前缀通过配置文件设置。
 */
use think\migration\Migrator;
use think\migration\db\Column;

class CreateConfigHistoryTable extends Migrator
{
    protected function getTable(): string
    {
        return 'config_history';
    }

    public function up(): void
    {
        $tableName = $this->getTable();
        $table = $this->table($tableName, [
            'engine'    => 'InnoDB',
            'comment'   => '配置变更历史表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::integer('config_id')->setUnsigned(true)->setComment('关联配置ID'))
            ->addColumn(Column::string('namespace', 100)->setDefault('default')->setComment('命名空间'))
            ->addColumn(Column::string('key', 100)->setComment('配置键名'))
            ->addColumn(Column::text('old_value')->setNullable()->setComment('旧值'))
            ->addColumn(Column::text('new_value')->setNullable()->setComment('新值'))
            ->addColumn(Column::integer('user_id')->setDefault(0)->setComment('操作用户ID'))
            ->addColumn(Column::string('ip', 45)->setDefault('')->setComment('请求IP'))
            ->addColumn(Column::dateTime('create_time')->setComment('操作时间'))
            ->addIndex('config_id')
            ->addIndex('key')
            ->addIndex('user_id')
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
