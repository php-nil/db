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

        // 3. 标识符转义（自动适配数据库驱动）
        $escapedTable = $conn->quoteSingleIdentifier('ai_article');
        $escapedFields = array_map([$conn, 'quoteSingleIdentifier'], $fieldList);

        // 4. 构建参数绑定与 VALUES 行（完全复用 insertMany 的参数规范）
        $params = [];
        $types = [];
        $valueRows = [];
        $rowIndex = 0;

        foreach ($data as $row) {
            $placeholders = [];
            foreach ($fieldList as $field) {
                // 参数名不带冒号（Doctrine 绑定规范），SQL 中拼接冒号
                $paramName = "{$field}_{$rowIndex}";
                $placeholders[] = ":{$paramName}";
                // 缺失字段自动补 NULL
                $value = \array_key_exists($field, $row) ? $row[$field] : null;
                $params[$paramName] = $value;
                $types[$paramName] = Query::valueCheckType($value);
            }
            $valueRows[] = '(' . implode(', ', $placeholders) . ')';
            $rowIndex++;
        }

        // 6. 组装最终 SQL（严格遵循 INSERT INTO ... VALUES ... 结构）
        $fieldsClause = implode(', ', $escapedFields);
        $valuesClause = implode(', ', $valueRows);

        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $escapedTable,
            $fieldsClause,
            $valuesClause
        );

        // return [$sql, $params, $types];

        // 7. 执行并返回影响行数
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

        // 提前转义所有标识符（表名、主键），自动适配数据库驱动
        $escapedTable = $conn->quoteSingleIdentifier($this->table);
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

        // 2. 构建参数绑定与 CASE WHEN 子句
        $params = [];
        $types = [];
        $caseWhenPerField = [];
        $pkPlaceholders = [];
        $rowIndex = 0;

        foreach ($data as $pkValue => $row) {
            // 参数名不带冒号，仅 SQL 中拼接冒号（符合 Doctrine 绑定规范）
            $pkParamName = "pk_{$rowIndex}";
            $pkPlaceholders[] = ":{$pkParamName}";
            $params[$pkParamName] = $pkValue;
            $types[$pkParamName] = Query::valueCheckType($pkValue);

            // 生成每个字段的 WHEN 分支
            foreach (array_keys($fields) as $field) {
                if (\array_key_exists($field, $row)) {
                    $valueParamName = "{$field}_{$rowIndex}";
                    $caseWhenPerField[$field][] = "WHEN {$escapedPrimaryKey} = :{$pkParamName} THEN :{$valueParamName}";
                    $params[$valueParamName] = $row[$field];
                    $types[$valueParamName] = Query::valueCheckType($row[$field]);
                }
            }

            $rowIndex++;
        }

        // 3. 构建 SET 子句（补充 ELSE 保留原值，避免未指定字段被置 NULL）
        $setParts = [];
        foreach (array_keys($fields) as $field) {
            if (empty($caseWhenPerField[$field])) {
                continue;
            }
            $whenClauses = implode(' ', $caseWhenPerField[$field]);
            $setParts[] = "{$escapedFields[$field]} = CASE {$whenClauses} ELSE {$escapedFields[$field]} END";
        }

        if (empty($setParts)) {
            return 0;
        }

        // 4. 构建 WHERE IN 条件
        $whereInClause = implode(', ', $pkPlaceholders);

        // 5. 组装最终 SQL（标识符用反引号转义）
        $sql = \sprintf(
            'UPDATE %s SET %s WHERE %s IN (%s)',
            $escapedTable,
            implode(', ', $setParts),
            $escapedPrimaryKey,
            $whereInClause
        );

        // 6. 执行并返回影响行数
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

    // TODO
    /**
     * 聚合函数 - 取多个值 - 分组
     * 
     * ['age'=>'SUM','class'=>'SUM','SUM(PAGE) AS name',['COUNT(name) as name','name']],['name']
     */

    // 批量插入 insertBatch
}
