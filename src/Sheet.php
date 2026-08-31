<?php

namespace NilDB;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

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
     * 批量更新多条数据（基于主键）
     * 
     * 用法示例：
     * $data = [
     *     1 => ['status' => 'active', 'score' => 100],
     *     2 => ['status' => 'inactive', 'score' => 0],
     * ];
     * $model->updateMany('id', $data);
     * 
     * @param string $primaryKey 主键字段名
     * @param array $data 待更新数据，键为主键值，值为「字段=>更新值」的关联数组
     * @return int 受影响行数
     * @throws \InvalidArgumentException 参数非法时抛出
     */
    public function updateMany(string $primaryKey, array $data): int
    {
        // 前置校验：空数据直接返回，避免无效计算
        if (empty($data)) {
            throw new \InvalidArgumentException('更新数据不能为空');
        }
        if (trim($primaryKey) === '') {
            throw new \InvalidArgumentException('主键字段名不能为空');
        }

        // 1. 高效收集所有待更新字段（键名去重法，O(n)复杂度）
        $fields = [];
        foreach ($data as $row) {
            if (!\is_array($row)) {
                throw new \InvalidArgumentException('每条更新数据必须为数组格式');
            }
            foreach ($row as $field => $value) {
                $fields[$field] = true;
            }
        }
        $fields = array_keys($fields);

        // 无有效更新字段直接返回
        if (empty($fields)) {
            return 0;
        }

        // 2. 构建参数绑定与每个字段的 CASE WHEN 子句
        $params = [];
        $types = [];
        $caseWhenPerField = []; // 结构：[字段名 => [WHEN子句集合]]
        $pkPlaceholders = [];
        $rowIndex = 0;

        foreach ($data as $pkValue => $row) {
            // 主键占位符与参数绑定
            $pkPlaceholder = ":pk_{$rowIndex}";
            $pkPlaceholders[] = $pkPlaceholder;
            $params[$pkPlaceholder] = $pkValue;
            $types[$pkPlaceholder] = Query::valueCheckType($pkValue);

            // 为当前行的指定字段生成 WHEN 分支
            foreach ($fields as $field) {
                if (array_key_exists($field, $row)) {
                    $valuePlaceholder = ":{$field}_{$rowIndex}";
                    $caseWhenPerField[$field][] = "WHEN `{$primaryKey}` = {$pkPlaceholder} THEN {$valuePlaceholder}";
                    $params[$valuePlaceholder] = $row[$field];
                    $types[$valuePlaceholder] = Query::valueCheckType($row[$field]);
                }
            }

            $rowIndex++;
        }

        // 3. 构建 SET 子句（核心修复：补充 ELSE 保持原值，避免未指定字段被置NULL）
        $setParts = [];
        foreach ($fields as $field) {
            if (empty($caseWhenPerField[$field])) {
                continue;
            }
            $whenClauses = implode(' ', $caseWhenPerField[$field]);
            // 标识符用反引号转义，ELSE 保留字段原值
            $setParts[] = "`{$field}` = CASE {$whenClauses} ELSE `{$field}` END";
        }

        if (empty($setParts)) {
            return 0;
        }

        // 4. 构建 WHERE IN 条件
        $whereInClause = implode(', ', $pkPlaceholders);

        // 5. 组装最终 SQL（表名、主键均做标识符转义）
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` IN (%s)',
            $this->table,
            implode(', ', $setParts),
            $primaryKey,
            $whereInClause
        );

        // 6. 执行并返回影响行数
        return $this->getConnection()->executeStatement($sql, $params, $types);
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

    // 批量插入 insertBatch
}
