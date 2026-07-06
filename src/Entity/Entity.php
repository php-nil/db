<?php

namespace NilDB\Entity;

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
        $this->sqlColumnReplace = new ColumnNameReplace($options['columnReplace']);

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
        // 转换字段
        $inserts = [];
        foreach ($data as $key => $value) {
            $location = $this->options['columnTable'][$key];
            $column = $this->options['columnReal'][$key];
            $inserts[$location][$column] = $value;
        }
        $dt = $this->entities->data;

        // 主表
        $id = $dt->sheet($this->table_main)->insertGetId($inserts[Definition::LOCATION_MAIN]);
        unset($inserts[Definition::LOCATION_MAIN]);

        // 其他表
        foreach ($inserts as $location => $data) {
            $table = $this->options['tables'][$location];
            $data['id'] = $id;
            $dt->sheet($table)->insert($data);
        }

        return $id;
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
        $update = [];
        foreach ($data as $key => $value) {
            $location = $this->options['columnTable'][$key];
            $column = $this->options['columnReal'][$key];
            $update[$location][$column] = $value;
        }

        foreach ($update as $location => $data) {
            $table = $this->options['tables'][$location];
            $num = $this->entities->data->sheet($table)->update($data, ['id' => $id]);
        }

        return $num;
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
        foreach ($this->options['tables'] as $table) {
            $num = $this->entities->data->sheet($table)->delete(['id' => $id]);
        }
        return $num;
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

        Query::setColumnNameReplace($this->sqlColumnReplace);
        $query = $this->entities->data->getQuery()->from(
            $this->table_main,
            Definition::LOCATION_MAIN,
            $join
        );
        $query->select($column, $where, $limit, $order);
        Query::setColumnNameReplace(null);

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
