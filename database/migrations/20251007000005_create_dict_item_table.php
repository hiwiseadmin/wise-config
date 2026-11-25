<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateDictItemTable extends Migrator
{
    protected function getTable(): string
    {
        return 'dict_item';
    }

    public function up(): void
    {
        $tableName = $this->getTable();
        $table = $this->table($tableName, [
            'engine'    => 'InnoDB',
            'comment'   => '字典项表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('dict_code', 100)->setComment('关联字典编码'))
            ->addColumn(Column::string('item_value', 255)->setComment('存储值'))
            ->addColumn(Column::string('item_label', 255)->setComment('显示标签'))
            ->addColumn(Column::integer('parent_id')->setDefault(0)->setComment('父级ID（0=顶级）'))
            ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序号'))
            ->addColumn(Column::boolean('status')->setDefault(1)->setComment('状态：0禁用 1启用'))
            ->addColumn(Column::string('remark', 500)->setNull(true)->setComment('备注'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'))
            ->addIndex(['dict_code'], ['name' => 'idx_dict_code'])
            ->addIndex(['parent_id'], ['name' => 'idx_parent_id'])
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
