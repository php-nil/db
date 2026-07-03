<?php

namespace NilDB;

use Doctrine\DBAL\Connection;

/**
 * 单表处理
 */
class Sheet
{
    public function __construct(public readonly Data $data, public readonly string $table)
    {
    }

    /**
     * 更换数据表
     * 返回新的sheet
     */
    public function changeTable(string $table): self
    {
        return $this->data->sheet($table);
    }

    /**
     * 获取数据连接
     */
    public function getConnection(): Connection
    {
        return $this->data->connection;
    }

    protected ?Query $query = null;

    /**
     * getQuery
     * 每次调用会生成新的
     */
    public function getQuery()
    {
        return $this->query = new Query($this->data, $this->table);
    }

    public function lastQuery()
    {
        return $this->query;
    }

    /**
     * 插入一条数据
     */
    public function insert(array $data)
    {
        return $this->getQuery()->insert($data)->executeStatement();
    }

    /**
     * 插入并返回 lastInsertId
     */
    public function insertGetId(array $data)
    {
        return ($this->insert($data) == 0)
            ? false
            : $this->getConnection()->lastInsertId();
    }

    /**
     * 更新
     */
    public function update(array $data, array|string|null $where)
    {
        return $this->getQuery()->update($data, $where)->executeStatement();
    }

    /**
     * 删除数据
     */
    public function delete(array|string|null $where)
    {
        return $this->getQuery()->delete($where)->executeStatement();
    }

    /**
     * 
     * 查询
     */
    public function select(array|string|null $column = null, array|string|null $where = null, array|int|null $limit = null, array|string|null $order = null)
    {
        return $this->getQuery()->select($column, $where, $limit, $order);
    }

    /**
     * 获取全部
     */
    public function fetchAll(array|string|null $column = null, array|string|null $where = null, array|int|null $limit = null, array|string|null $order = null)
    {
        return $this->select($column, $where, $limit, $order)->fetchAll();
    }

    /**
     * 获取一行
     */
    public function fetchRow(array|string|null $column = null, array|string|null $where = null, array|string|null $order = null)
    {
        return $this->select($column, $where, null, $order)->fetchRow();
    }

    /**
     * 获取一行 第一个字段
     */
    public function fetchOne(array|string $column, array|string|null $where = null, array|string|null $order = null)
    {
        return $this->select($column, $where, null, $order)->fetchOne();
    }

    /**
     * 获取全部 第一个字段
     */
    public function fetchAllOne(array|string $column, array|string|null $where = null, array|string|null $order = null)
    {
        return $this->select($column, $where, null, $order)->fetchAllOne();
    }

    /**
     * 聚合函数 - 取一个值
     */
    protected function aggregation(array|string|null $where, ?string $column, string $func, ?string $pre = null)
    {
        $column = null !== $column ? Query::replaceColumnName($column) : '*';
        $pre = null === $pre ? '' : "{$pre} ";

        return $this->select("{$func}({$pre}{$column})", $where)->fetchOne();
    }

    /**
     * 统计个数
     */
    public function count(array|string|null $where = null, ?string $column = null)
    {
        return $this->aggregation($where, $column, 'COUNT');
    }

    /**
     * 统计个数-去重
     */
    public function countDistinct(array|string|null $where, string $column)
    {
        return $this->aggregation($where, $column, 'COUNT', 'DISTINCT');
    }

    /**
     * 求和
     */
    public function sum(array|string|null $where, string $column)
    {
        return $this->aggregation($where, $column, 'SUM');
    }

    /**
     * 最大值
     */
    public function max(array|string|null $where, string $column)
    {
        return $this->aggregation($where, $column, 'MAX');
    }

    /**
     * 最小值
     */
    public function min(array|string|null $where, string $column)
    {
        return $this->aggregation($where, $column, 'MIN');
    }

    /**
     * 平均值
     */
    public function avg(array|string|null $where, string $column)
    {
        return $this->aggregation($where, $column, 'AVG');
    }

    // TODO
    /**
     * 聚合函数 - 取多个值 - 分组
     * 
     * ['age'=>'SUM','class'=>'SUM','SUM(PAGE) AS name',['COUNT(name) as name','name']],['name']
     */
}
