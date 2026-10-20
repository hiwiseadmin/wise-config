<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateDictTypeTable extends Migrator
{
    protected function getTable(): string
    {
        return 'dict_type';
    }

    public function up(): void
    {
        $tableName = $this->getTable();
        $table = $this->table($tableName, [
            'engine'    => 'InnoDB',
            'comment'   => '字典类型表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('dict_code', 100)->setComment('字典编码'))
            ->addColumn(Column::string('name', 100)->setComment('字典名称'))
            ->addColumn(Column::string('description', 500)->setNull(true)->setComment('字典描述'))
            ->addColumn(Column::boolean('is_tree')->setDefault(0)->setComment('是否树形字典'))
            ->addColumn(Column::boolean('status')->setDefault(1)->setComment('状态：0禁用 1启用'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'))
            ->addIndex(['dict_code'], ['unique' => true, 'name' => 'uk_dict_code'])
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
