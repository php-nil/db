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
        return (0 === $this->insert($data))
            ? false
            : $this->getConnection()->lastInsertId();
    }

    /**
     * 批量 Insert插入多条记录
     * 
     * 基础SQL结构：INSERT INTO 表 (字段) VALUES (值1), (值2) ...
     * 
     * @return int 受影响行数
     * 
     * @throws \InvalidArgumentException 数据格式错误
     */
    public function insertMany(array $data): int
    {
        // 前置校验
        if (empty($data)) {
            throw new \InvalidArgumentException('插入数据不能为空');
        }

        $conn = $this->getConnection();

        // 1. 收集所有字段（取并集，自动对齐行之间的字段差异）
        $fields = [];
        foreach ($data as $index => $row) {
            if (!\is_array($row)) {
                throw new \InvalidArgumentException(sprintf('第 %d 行数据必须为数组格式', $index));
            }
            foreach ($row as $field => $value) {
                $fields[$field] = true;
            }
        }
        $fieldList = array_keys($fields);
        if (empty($fieldList)) {
            throw new \InvalidArgumentException('插入数据不能包含空字段');
        }

        // 2. 标识符转义（自动适配数据库驱动）
        $escapedFields = array_map([$conn, 'quoteSingleIdentifier'], $fieldList);

        // 3. 构建位置参数绑定与 VALUES 行，缺失字段自动补 NULL
        $params = [];
        $types = [];
        $valueRows = [];

        foreach ($data as $row) {
            $placeholders = [];
            foreach ($fieldList as $field) {
                $placeholders[] = '?';
                $value = \array_key_exists($field, $row) ? $row[$field] : null;
                $params[] = $value;
                $types[] = Query::valueCheckType($value);
            }
            $valueRows[] = '(' . implode(', ', $placeholders) . ')';
        }

        // 4. 组装并执行
        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $conn->quoteSingleIdentifier($this->table),
            implode(', ', $escapedFields),
            implode(', ', $valueRows)
        );

        return $conn->executeStatement($sql, $params, $types);
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

        $conn = $this->getConnection();

        // 提前转义
        $escapedPrimaryKey = $conn->quoteSingleIdentifier($primaryKey);

        // 1. 收集并转义所有待更新字段
        $fields = [];
        $escapedFields = [];
        foreach ($data as $row) {
            if (!\is_array($row)) {
                throw new \InvalidArgumentException('每条更新数据必须为数组格式');
            }
            foreach ($row as $field => $value) {
                if (!isset($fields[$field])) {
                    $fields[$field] = true;
                    $escapedFields[$field] = $conn->quoteSingleIdentifier($field);
                }
            }
        }

        if (empty($fields)) {
            return 0;
        }

        // 2. 构建 SET 子句与位置参数（绑定顺序须与 SQL 中占位符顺序一致）
        $params = [];
        $types = [];
        $setParts = [];

        foreach (array_keys($fields) as $field) {
            $whenClauses = [];
            foreach ($data as $pkValue => $row) {
                if (!\array_key_exists($field, $row)) {
                    continue;
                }
                // WHEN pk = ? THEN ?
                $whenClauses[] = "WHEN {$escapedPrimaryKey} = ? THEN ?";
                $params[] = $pkValue;
                $types[] = Query::valueCheckType($pkValue);
                $params[] = $row[$field];
                $types[] = Query::valueCheckType($row[$field]);
            }
            // 防御性分支：field 来源于行数据键收集，至少一行包含该字段，whenClauses 必非空
            // @codeCoverageIgnoreStart
            if (empty($whenClauses)) {
                continue;
            }
            // @codeCoverageIgnoreEnd
            // ELSE 保留原值，避免未指定字段被置 NULL
            $setParts[] = "{$escapedFields[$field]} = CASE " . implode(' ', $whenClauses)
                . " ELSE {$escapedFields[$field]} END";
        }

        // 防御性分支：fields 非空且每个字段都产生 SET 片段，setParts 必非空
        // @codeCoverageIgnoreStart
        if (empty($setParts)) {
            return 0;
        }
        // @codeCoverageIgnoreEnd

        // 3. WHERE IN 条件（占位符排在 SET 子句之后）
        $inPlaceholders = [];
        foreach ($data as $pkValue => $_) {
            $inPlaceholders[] = '?';
            $params[] = $pkValue;
            $types[] = Query::valueCheckType($pkValue);
        }

        // 4. 组装并执行
        $sql = \sprintf(
            'UPDATE %s SET %s WHERE %s IN (%s)',
            $conn->quoteSingleIdentifier($this->table),
            implode(', ', $setParts),
            $escapedPrimaryKey,
            implode(', ', $inPlaceholders)
        );

        return $conn->executeStatement($sql, $params, $types);
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
}
