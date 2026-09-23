<?php

namespace NilDB\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use InvalidArgumentException;

/**
 * 实体创建
 */
class EntityCreater
{
    /**
     * 字段定义明细，按存储位置分组
     */
    protected array $detail = [];
    protected array $indexList = [];

    /**
     * 字段对应的存储位置
     * ['name'=>'main','time'=>'main','contant'=>'info1']
     */
    protected array $columnLocationMap = [];

    public function addColumn($name, $typeName, string $location = 'main', array $options = [])
    {
        // 字段信息
        $location = strtolower($location);
        $this->detail[$location][$name] = ['type' => $typeName, 'options' => $options, 'location' => $location];
        $this->columnLocationMap[$name] = $location;

        return $this;
    }

    public function addIndex(array $columnNames, array $flags = [], array $options = [], bool $isUnique = false)
    {
        if (empty($columnNames)) {
            throw new InvalidArgumentException('索引字段不能为空');
        }

        // 复合索引必须位于同一张（分）表
        $location = '';
        foreach ($columnNames as $k) {
            if (!isset($this->columnLocationMap[$k])) {
                throw new InvalidArgumentException("索引字段未定义: {$k}");
            }

            if ('' === $location) {
                $location = $this->columnLocationMap[$k];
            } elseif ($location !== $this->columnLocationMap[$k]) {
                throw new InvalidArgumentException('索引字段必须位于同一张分表');
            }
        }

        $this->indexList[$location][] = [
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
     * 创建实体：元数据写入与建表 DDL 在同一事务内，任一失败整体回滚。
     * MySQL/MariaDB 的 DDL 隐式提交、无法回滚，该平台不包裹事务直接执行。
     */
    public function create($name, Entities $entities)
    {
        if (empty($this->detail[Definition::LOCATION_MAIN])) {
            $this->detail[Definition::LOCATION_MAIN] = [];
        }

        $con = $entities->data->connection;

        $work = function () use ($name, $entities, $con) {
            $option = $entities->getOption();

            // 判断是否存在
            $table_id = $option->add('entity', $name);
            $tableOption = [
                'columnTable' => [],
                'columnReplace' => [],
                'columnReal' => [],
                'tables' => []
            ];

            // 获取基础表
            $table_base_name = $entities->getRealTable(Definition::TABLE_BASE . $table_id);
            // 依此处理
            $queries = [];
            foreach ($this->detail as $location => $da) {
                $schema = new Schema();
                $table_name = $table_base_name . '_' . $location;
                $myTable = $schema->createTable($table_name);
                $tableOption['tables'][$location] = $table_name;

                // 主键
                $pmop = ['unsigned' => true];
                if (Definition::LOCATION_MAIN === $location) {
                    $tableOption['columnReplace']['id'] = 'main.id';
                    $tableOption['columnReal']['id'] = 'id';
                    $tableOption['columnTable']['id'] = $location;
                    $pmop['autoincrement'] = true;
                }
                $myTable->addColumn(Definition::COLUMN_PRIMARY, 'bigint', $pmop);

                // 字段
                $columnIDMap = [];
                foreach ($da as $key => $opt) {
                    $column_id = $option->add('entity_column', $key, $table_id, $opt);
                    $column_name = Definition::COLUMN_BASE_NAME . $column_id;
                    $columnIDMap[$key] = ['id' => $column_id, 'name' => $column_name];
                    $tableOption['columnReplace'][$key] = $location . '.' . $column_name;
                    $tableOption['columnReal'][$key] = $column_name;
                    $tableOption['columnTable'][$key] = $location;

                    // string 未显式指定长度时给默认值 255：PostgreSQL/SQLite 平台本身按
                    // 255 兜底，但 MySQL 要求 VARCHAR 必须显式声明长度，否则建表失败
                    $columnOptions = $opt['options'];
                    if ('string' === $opt['type'] && !isset($columnOptions['length'])) {
                        $columnOptions['length'] = 255;
                    }
                    $myTable->addColumn($column_name, $opt['type'], $columnOptions);
                }

                // 索引
                // DBAL 4 起 setPrimaryKey() 已弃用，改用 addPrimaryKeyConstraint()
                $myTable->addPrimaryKeyConstraint(
                    PrimaryKeyConstraint::editor()
                        ->setUnquotedColumnNames(Definition::COLUMN_PRIMARY)
                        ->create()
                );
                if (isset($this->indexList[$location])) {
                    foreach ($this->indexList[$location] as $la) {
                        $column_names = [];
                        $column_ids = [];
                        foreach ($la['column'] as $i) {
                            $column_ids[] = $columnIDMap[$i]['id'];
                            $column_names[] = $columnIDMap[$i]['name'];
                        }
                        $key = uniqid($location);
                        $la['columnid'] = $column_ids;
                        $index_id = $option->add('entity_index', $key, $table_id, $la);
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

            // 执行建表
            foreach ($queries as $qs) {
                foreach ($qs as $q) {
                    $con->executeStatement($q);
                }
            }

            return [$table_id, $tableOption];
        };

        // MySQL/MariaDB 的 DDL 会隐式提交事务：包裹事务既无法回滚 DDL，
        // DDL 后 DBAL 再 COMMIT 还会抛 NoActiveTransaction，故该平台直接执行
        // （中途失败可能残留半成品，见 README 遗留 6.4）；其他平台保持事务原子性
        if ($con->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return $work();
        }

        return $con->transactional($work);
    }
}
