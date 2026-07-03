<?php

namespace NilDB\Entity;

use Doctrine\DBAL\Schema\Schema;

/**
 * 实体创建
 */
class EntityCreater
{
    protected $index = [];

    protected $deatil = [];
    protected $indexs = [];

    /**
     * 字段对应的存储位置
     * ['name'=>'main','time'=>'main','contant'=>'info1']
     */
    protected $columnLoactionMap = [];

    public function addColumn($name, $typeName, string $location = 'main', array $options = [])
    {
        // 字段信息
        $location = strtolower($location);
        $this->deatil[$location][$name] = ['type' => $typeName, 'options' => $options, 'location' => $location];
        $this->columnLoactionMap[$name] = $location;

        return $this;
    }

    public function addIndex(array $columnNames, array $flags = [], array $options = [], bool $isUnique = false)
    {
        // 怎么存 表信息 id呢 获取id 组合在一起
        $location = '';
        foreach ($columnNames as $k) {
            if (!isset($this->columnLoactionMap[$k])) {
                throw new \Error("字段未定义");
            }

            if ('' == $location) {
                $location = $this->columnLoactionMap[$k];
            } elseif ($location != $this->columnLoactionMap[$k]) {
                throw new \Error("索引必须在同一个不位置");
            }
        }

        $this->indexs[$location][] = [
            'isUnique' => $isUnique,
            'column' => $columnNames,
            'flags' => $flags,
            'options' => $options
        ];

        return $this;
    }

    public function addUniqueIndex(array $columnNames, array $options = [])
    {
        return $this->addIndex($columnNames, [], $options, true);
    }

    /**
     * 创建
     * 需要做异常处理
     */
    public function create($name, Entities $entities)
    {
        if (empty($this->deatil[Definition::LOCATION_MAIN])) {
            $this->deatil[Definition::LOCATION_MAIN] = [];
        }

        $option = $entities->getOption();

        // 判断是否存在
        $table_id = $option->add('entity', $name);
        $option_ids = [$table_id];
        $tableOption = [
            'columnTable' => [],
            'columnReplace' => [],
            'columnReal' => [],
            'tables' => []
        ];

        // 获取基础表
        $table_base_name = $entities->getRealTable(Definition::TABLE_BASE . $table_id);
        // 执行
        $con = $entities->data->connection;
        // 依此处理
        $queries = [];
        foreach ($this->deatil as $location => $da) {
            $schema = new Schema();
            $table_name = $table_base_name . '_' . $location;
            $myTable = $schema->createTable($table_name);
            $tableOption['tables'][$location] = $table_name;

            // 主键
            $pmop = ["unsigned" => true];
            if (Definition::LOCATION_MAIN == $location) {
                $tableOption['columnReplace']['id'] = 'main.id';
                $tableOption['columnReal']['id'] = 'id';
                $tableOption['columnTable']['id'] = $location;
                $pmop['autoincrement'] = true;
            }
            $myTable->addColumn(Definition::COLUMN_PRIMARY, "bigint", $pmop);

            // 字段
            $columnIDMap = [];
            foreach ($da as $key => $opt) {
                // $key 转化为 id
                $column_id = $option->add('entity_column', $key, $table_id, $opt);
                $option_ids[] = $column_id;
                $column_name = Definition::COLUMN_BASE_NAME . $column_id;
                $columnIDMap[$key] = ['id' => $column_id, 'name' => $column_name];
                $tableOption['columnReplace'][$key] = $location . '.' . $column_name;
                $tableOption['columnReal'][$key] = $column_name;
                $tableOption['columnTable'][$key] = $location;

                $myTable->addColumn($column_name, $opt['type'], $opt['options']);
            }

            // 索引
            $myTable->setPrimaryKey([Definition::COLUMN_PRIMARY]);
            if (isset($this->indexs[$location])) {
                foreach ($this->indexs[$location] as $la) {
                    $column_names = [];
                    $column_ids = [];
                    foreach ($la['column'] as $i) {
                        $column_ids[] = $columnIDMap[$i]['id'];
                        $column_names[] = $columnIDMap[$i]['name'];
                    }
                    ;
                    $key = uniqid($location);
                    $la['columnid'] = $column_ids;
                    $index_id = $option->add('entity_index', $key, $table_id, $la);
                    $option_ids[] = $index_id;
                    $index_name = Definition::INDEX_BASE_NAME . $location . '_' . $index_id;
                    $option->updateName($index_id, $index_name);

                    // 是否非重复
                    if ($la['isUnique']) {
                        $myTable->addUniqueIndex($column_names, $index_name, $la['options']);
                    } else {
                        $myTable->addIndex($column_names, $index_name, $la['flags'], $la['options']);
                    }
                }
            }
            $myTable->setComment($name);

            $queries[] = $schema->toSql($con->getDatabasePlatform());
        }

        // 更新表信息
        $option->updateOption($table_id, $tableOption);

        // 此处应该有一个异常处理
        foreach ($queries as $qs) {
            foreach ($qs as $q) {
                $con->executeStatement($q);
            }
        }

        return [$table_id, $tableOption];
    }
}
