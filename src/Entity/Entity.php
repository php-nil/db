<?php

namespace NilDB\Entity;

use InvalidArgumentException;
use RuntimeException;
use NilDB\Query;
use NilDB\ColumnNameReplace;

/**
 * 实体
 */
class Entity
{
    protected $sqlColumnReplace;

    protected $table_main;
    protected $table_more;
    // 字段信息

    public function __construct(public readonly Entities $entities, public readonly int $id, public readonly string $name, public readonly array $options)
    {
        $this->sqlColumnReplace = new ColumnNameReplace($options['columnReplace'] ?? []);

        // 主附表处理
        foreach ($this->options['tables'] as $location => $va) {
            if ($location == Definition::LOCATION_MAIN) {
                $this->table_main = $va;
            } else {
                $this->table_more[$location] = $va;
            }
        }
    }

    /**
     * 校验写入字段全部已定义
     */
    protected function checkFields(array $data): void
    {
        foreach (array_keys($data) as $key) {
            if (!isset($this->options['columnTable'][$key])) {
                throw new InvalidArgumentException(sprintf('实体(%s)未定义字段: %s', $this->name, $key));
            }
        }
    }

    /**
     * 获取真实的表
     */
    public function getRealTable(?string $name = null)
    {
        if (null === $name) {
            return $this->table_main;
        }

        if (isset($this->options['tables'][$name])) {
            return $this->options['tables'][$name];
        }

        throw new \Error("表不存在");
    }

    //==== 数据增删改 =====

    public function insert(array $data)
    {
        $this->checkFields($data);

        // 转换字段
        $inserts = [];
        foreach ($data as $key => $value) {
            $location = $this->options['columnTable'][$key];
            $column = $this->options['columnReal'][$key];
            $inserts[$location][$column] = $value;
        }
        $dt = $this->entities->data;

        // 主附表写入必须在同一事务内，避免部分失败产生孤儿数据
        return $dt->transaction(function () use ($dt, $inserts) {
            // 主表
            $id = $dt->sheet($this->table_main)->insertGetId($inserts[Definition::LOCATION_MAIN] ?? []);
            if (false === $id) {
                throw new RuntimeException(sprintf('实体(%s)主表写入失败', $this->name));
            }
            unset($inserts[Definition::LOCATION_MAIN]);

            // 其他表
            foreach ($inserts as $location => $row) {
                $table = $this->options['tables'][$location];
                $row['id'] = $id;
                $dt->sheet($table)->insert($row);
            }

            return $id;
        });
    }

    public function update(array $data, array|null $where)
    {
        $ids = $this->fetchAll(['id'], $where);
        if (empty($ids)) {
            return 0;
        }
        return $this->updateByID($data, array_column($ids, 'id'));
    }

    public function updateByID(array $data, int|array $id)
    {
        $this->checkFields($data);

        $update = [];
        foreach ($data as $key => $value) {
            $location = $this->options['columnTable'][$key];
            $column = $this->options['columnReal'][$key];
            $update[$location][$column] = $value;
        }

        if (empty($update)) {
            return 0;
        }

        // 多表更新同一事务；返回各表受影响行数之和
        return $this->entities->data->transaction(function () use ($update, $id) {
            $num = 0;
            foreach ($update as $location => $row) {
                $table = $this->options['tables'][$location];
                $num += $this->entities->data->sheet($table)->update($row, ['id' => $id]);
            }
            return $num;
        });
    }

    public function delete($where)
    {
        $ids = $this->fetchAll(['id'], $where);
        if (empty($ids)) {
            return 0;
        }
        return $this->deleteByID(array_column($ids, 'id'));
    }

    public function deleteByID(int|array $id)
    {
        // 多表删除同一事务；返回各表受影响行数之和
        return $this->entities->data->transaction(function () use ($id) {
            $num = 0;
            foreach ($this->options['tables'] as $table) {
                $num += $this->entities->data->sheet($table)->delete(['id' => $id]);
            }
            return $num;
        });
    }

    //==== 查询 =====
    public function query(array|string $column, array|null $where = null, array|int|null $limit = null, array|null $order = null)
    {
        if (!empty($this->table_more)) {
            $join = [];
            foreach ($this->table_more as $location => $name) {
                $join[$location] = $name;
            }
        } else {
            $join = null;
        }

        // 附表可能尚无对应行，必须 LEFT JOIN，否则仅主表记录会丢失
        Query::setColumnNameReplace($this->sqlColumnReplace);
        try {
            $query = $this->entities->data->getQuery()->from(
                $this->table_main,
                Definition::LOCATION_MAIN,
                $join,
                'left'
            );
            $query->select($column, $where, $limit, $order);
        } finally {
            Query::setColumnNameReplace(null);
        }

        return $query;
    }

    public function fetchAll(array|string $column, array|null $where = null, array|int|null $limit = null, array|null $order = null)
    {
        return $this->query($column, $where, $limit, $order)->fetchAll();
    }

    public function fetchRow(array|string $column, array|null $where = null, array|null $order = null)
    {
        return $this->query($column, $where, null, $order)->fetchRow();
    }

    public function fetchOne(array|string $column, array|null $where = null, array|null $order = null)
    {
        return $this->query($column, $where, null, $order)->fetchOne();
    }
}
