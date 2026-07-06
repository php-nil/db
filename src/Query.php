<?php

namespace NilDB;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use NilDB\Helper\QueryJoin;
use NilDB\Helper\QueryWhere;
use RuntimeException;

/**
 * 查询SQL生成和请求
 */
class Query
{
    // 表
    protected readonly QueryBuilder $queryBuilder;

    /**
     * The table name for an INSERT, UPDATE or DELETE query.
     */
    protected ?string $table = null;

    protected ?string $alias = null;

    protected ?Result $result = null;

    public function __construct(public readonly Data $data, ?string $table = null)
    {
        $this->queryBuilder = $data->connection->createQueryBuilder();

        if (null !== $table) {
            $this->from($table);
        }
    }

    /**
     * 字段替换
     */
    protected static ?ColumnNameReplace $columnNameReplace = null;

    public static function setColumnNameReplace(?ColumnNameReplace $columnNameReplace)
    {
        static::$columnNameReplace = $columnNameReplace;
    }

    public static function replaceColumnName(string $name)
    {
        return (null === static::$columnNameReplace)
            ? $name
            : static::$columnNameReplace->replace($name);
    }

    public static function getColumnName(string $name)
    {
        return (null === static::$columnNameReplace)
            ? null
            : static::$columnNameReplace->get($name);
    }

    /**
     * 查询 一个搞定
     */
    public function select(array|string|null $column = null, array|string|null $where = null, array|int|null $limit = null, array|string|null $order = null)
    {
        if (null !== $column) {
            $this->column($column);
        }
        if (null !== $where) {
            $this->where($where);
        }
        if (null !== $limit) {
            \is_int($limit) ? $this->limit($limit) : $this->limit(...$limit);
        }
        if (null !== $order) {
            $this->order($order);
        }

        return $this;
    }

    /**
     * 获取第一行
     */
    public function fetchRow()
    {
        return $this->queryBuilder->fetchAssociative();
    }

    /**
     * 获取第一行 - 以数字作为索引
     */
    public function fetchRowNumeric(): array|false
    {
        return $this->queryBuilder->fetchNumeric();
    }

    /**
     * 获取第一行 第一个字段
     */
    public function fetchOne(): mixed
    {
        return $this->queryBuilder->fetchOne();
    }

    /**
     * 获取全部 - 以数字作为索引
     */
    public function fetchAllNumeric(): array
    {
        return $this->queryBuilder->fetchAllNumeric();
    }

    /**
     * 获取全部
     */
    public function fetchAll(): array
    {
        return $this->queryBuilder->fetchAllAssociative();
    }

    /**
     * 获取全部 第一个作为键 第二个作为值
     * 
     * [1=>'a',2=>'b']
     */
    public function fetchAllKeyValue(): array
    {
        return $this->queryBuilder->fetchAllKeyValue();
    }

    /**
     * 获取全部 第一个作为键 其他作为值
     * 
     * [1=>['q'=>'a','a'=>'b'], 2=>['q'=>'e','a'=>'r']]
     */
    public function fetchAllIndexed(): array
    {
        return $this->queryBuilder->fetchAllAssociativeIndexed();
    }

    /**
     * 获取全部 只有第一个字段
     */
    public function fetchAllOne(): array
    {
        return $this->queryBuilder->fetchFirstColumn();
    }

    /**
     * 结果迭代器
     */
    public function resultIterate()
    {
        return $this->executeQuery()->iterateAssociative();
    }

    /**
     * 获取SQL语句
     */
    public function getSQL()
    {
        return $this->queryBuilder->getSQL();
    }

    /**
     * 获取SQL调试信息
     */
    public function getSQLAll()
    {
        return [
            $this->queryBuilder->getSQL(),
            $this->queryBuilder->getParameters(),
            $this->queryBuilder->getParameterTypes()
        ];
    }

    // 查询 结束

    // 单块处理 开始

    /**
     * 字段
     */
    public function column(string|array ...$expressions)
    {
        $fields = [];
        foreach ($expressions as $u) {
            $list = [];
            if (is_string($u)) {
                // 字符串
                $fields[] = $u;
            } else {
                // 数组
                // alias=>field
                // alias=>[field]
                // alias=>[field, replace]
                foreach ($u as $k => $v) {
                    if (is_int($k)) {
                        // 复杂情况
                        $list[] = (array) $v;
                    } else {
                        if (is_string($v)) {
                            $list[] = [$v, $k];
                        } elseif (is_array($v)) {
                            $list[] = [$v[0], $k, $v[1] ?? null];
                        } else {
                            throw new \Exception("column value should string or array");
                        }
                    }
                }
            }

            // 字段
            // [field, alias, replace]
            foreach ($list as $field) {
                if (empty($field[1])) {
                    $as = static::getColumnName($field[0]);
                    if (null !== $as) {
                        $fd = $as;
                        $as = $field[0];
                    } else {
                        $fd = $field[0];
                    }
                } elseif (empty($field[2])) {
                    $fd = static::replaceColumnName($field[0]);
                    $as = $field[1];
                } else {
                    // 复杂替换
                    $fd = $field[0];
                    $as = $field[1];
                    $replace = (array) $field[2];
                    $re = $rd = [];
                    foreach ($replace as $r) {
                        $n = static::getColumnName($r);
                        if (null !== $n) {
                            $re[] = $r;
                            $rd[] = $n;
                        }
                    }
                    if (!empty($re)) {
                        $fd = str_replace($re, $rd, $fd);
                    }
                }
                // 合并
                $fields[] = $fd . (empty($as) ? '' : ' AS "' . $as . '"');
            }
        }

        $this->queryBuilder->select(...$fields);

        return $this;
    }

    /**
     * 查询
     */
    public function where(string|array ...$expressions)
    {
        $where = (new QueryWhere($this, $expressions))->getSQL();

        if (null !== $where) {
            $this->queryBuilder->where($where);
        }

        return $this;
    }

    /**
     * from table
     */
    public function from(string $table, ?string $alias = null, ?array $join = null)
    {
        $this->table = $table;

        if (null !== $alias) {
            $this->alias = $alias;
        }

        // table
        $this->queryBuilder->from($table, $alias);

        if (null !== $join) {
            foreach ($join as $k => $v) {
                if (\is_array($v)) {
                    [$t, $f1, $f2] = $v;
                    if (null === $f2) {
                        $this->innerJoin($t, $k, $f1, $alias);
                    } else {
                        $this->innerJoin($t, $k, [$f1 => $f2], $alias);
                    }
                } else {
                    $this->innerJoin($v, $k, 'id', $alias);
                }
            }
        }

        return $this;
    }

    protected function _join(string $func, string $table, string $alias, string|array $on, ?string $fromAlias)
    {
        if (null === $fromAlias) {
            if (null === $this->alias) {
                throw new RuntimeException('table alias must defined');
            }
            $fromAlias = $this->alias;
        }

        // on 与前表之间的关系
        // id  [id=>uid,t.type=>b.type3,'bud = iaks',[b.ju,'=',$t]]
        $on = QueryJoin::on($this, $on, $fromAlias, $alias);
        $on = '(' . implode(' AND ', $on) . ')';

        $this->queryBuilder->$func($this->alias, $table, $alias, $on);
    }

    public function innerJoin(string $table, string $alias, string|array $on, ?string $fromAlias = null)
    {
        $this->_join('innerJoin', $table, $alias, $on, $fromAlias);

        return $this;
    }

    public function leftJoin(string $table, string $alias, string|array $on, ?string $fromAlias = null)
    {
        $this->_join('leftJoin', $table, $alias, $on, $fromAlias);

        return $this;
    }

    public function rightJoin(string $table, string $alias, string|array $on, ?string $fromAlias = null)
    {
        $this->_join('rightJoin', $table, $alias, $on, $fromAlias);

        return $this;
    }

    /**
     * 数量
     * @param int $limit
     * @param int $offset
     * @return static
     */
    public function limit(int $limit, int $offset = 0)
    {
        $this->queryBuilder->setMaxResults($limit)->setFirstResult($offset);

        return $this;
    }

    /**
     * 分页
     * @param int $page_number
     * @param int $page
     * @return Query
     */
    public function page(int $page_number, int $page = 1)
    {
        return $this->limit($page_number, $page_number * ($page - 1));
    }

    /**
     * 排序
     * 
     * ('dadas ASC')
     * (['asdqwd','asdas'])
     * (['qwd'=>'sas','dqwdqw'=>'qwdqw'])
     * (['qwd'=>'sas','dqwdqw'=>['qwdqw','zxc']])
     * @param string|array $expression
     * @return static
     */
    public function order(string|array $expression)
    {
        if (is_array($expression)) {
            foreach ($expression as $k => $v) {
                if (is_int($k)) {
                    $this->queryBuilder->addOrderBy($v);
                } else {
                    // 替换
                    $k = static::replaceColumnName($k);
                    // 两种情况
                    if (is_array($v)) {
                        // TODO
                        // CASE id WHEN 130 THEN 1 WHEN 129 THEN 2 WHEN 131 THEN 3 ELSE 4 END
                        $this->queryBuilder->addOrderBy('FIELD(' . $k . ",'" . implode("','", $v) . "')");
                    } else {
                        $this->queryBuilder->addOrderBy($k, (string) $v);
                    }
                }
            }
        } else {
            $this->queryBuilder->orderBy($expression);
        }

        return $this;
    }

    /**
     * 分组和条件
     */
    public function group(string|array $group, string|array|null $having = null)
    {
        // group
        $group = (array) $group;
        foreach ($group as &$c) {
            $c = static::replaceColumnName($c);
        }
        $this->queryBuilder->groupBy(...$group);

        // having
        if (null !== $having) {
            $this->queryBuilder->having(
                is_string($having) ? $having : (new QueryWhere($this, $having))->getSQL()
            );
        }

        return $this;
    }

    /**
     * 更新数据用
     */
    public function set(array $expression)
    {
        foreach ($expression as $k => $v) {
            if (is_array($v)) {
                // 待敲定
                foreach ($v as &$c) {
                    $c = static::replaceColumnName($c);
                }
                $v = implode(' ', $v);
            } else {
                $v = $this->createNamedParameter($v);
            }
            $this->queryBuilder->set(static::replaceColumnName($k), $v);
        }
        return $this;
    }

    /**
     * 定义insert
     */
    public function value(array $expression)
    {
        $z = [];
        foreach ($expression as $k => $v) {
            $z[static::replaceColumnName($k)] = $this->createNamedParameter($v);
        }
        $this->queryBuilder->values($z);

        return $this;
    }

    /**
     * Executes an SQL query (SELECT) and returns a Result.
     */
    public function executeQuery()
    {
        if (null === $this->result) {
            $this->result = $this->queryBuilder->executeQuery();
        }
        return $this->result;
    }

    // 单块处理 结束

    /**
     * 执行语句
     */
    public function executeStatement()
    {
        return $this->queryBuilder->executeStatement();
    }

    /**
     * 删除数据
     */
    public function delete(array|string|null $where, ?string $table = null)
    {
        if (isset($table)) {
            $this->table = $table;
        }
        if (isset($where)) {
            $this->where($where);
        }

        $this->queryBuilder->delete($this->table);

        return $this;
    }

    /**
     * 更新数据
     */
    public function update(array $set, array|string|null $where, ?string $table = null)
    {
        $this->set($set);
        if (null !== $table) {
            $this->table = $table;
        }
        if (isset($where)) {
            $this->where($where);
        }
        $this->queryBuilder->update($this->table);

        return $this;
    }

    /**
     * 新增数据
     */
    public function insert(array $value, ?string $table = null)
    {
        if (null !== $table) {
            $this->table = $table;
        }
        $this->value($value);
        $this->queryBuilder->insert($this->table);

        return $this;
    }

    /**
     * 创建参数
     * @param mixed $value
     * @return string
     */
    public function createNamedParameter(mixed $value)
    {
        return $this->queryBuilder->createNamedParameter(
            $value,
            static::valueCheckType($value)
        );
    }

    /**
     *检查数据类型
     * @param mixed $valus
     * @return ArrayParameterType|ParameterType
     */
    public static function valueCheckType($valus)
    {
        if (\is_resource($valus)) {
            return ParameterType::BINARY;
        }

        if (\is_bool($valus)) {
            return ParameterType::BOOLEAN;
        }

        if (\is_array($valus)) {
            if (\is_int(\current($valus))) {
                return ArrayParameterType::INTEGER;
            } else {
                return ArrayParameterType::STRING;
            }
        }

        if (\is_int($valus)) {
            return ParameterType::INTEGER;
        }

        // 最后么办法
        return ParameterType::STRING;
    }
}
