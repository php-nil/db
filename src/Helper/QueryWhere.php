<?php

namespace NilDB\Helper;

use NilDB\Query;


/**
 * 查询SQL生成 WHERES
 */
final class QueryWhere
{
    /**
     * 查询连接符号 直连
     */
    public const array CONNECTOR = [
        '=' => '=',
        '<>' => '<>',
        '!=' => '<>',
        '>' => '>',
        '>=' => '>=',
        '<' => '<',
        '<=' => '<=',
        'LIKE' => 'LIKE',
        'ILIKE' => 'ILIKE',
        '!LIKE' => 'NOT LIKE'
    ];
    /**
     * 查询连接符号 BETWEEN
     */
    public const array CONNECTOR_BETWEEN = [
        'BETWEEN' => 'BETWEEN',
        'NOT BETWEEN' => 'NOT BETWEEN',
        '!BETWEEN' => 'NOT BETWEEN'
    ];
    /**
     * 查询连接符号 IN
     */
    public const array CONNECTOR_IN = [
        'IN' => 'IN',
        'NOT IN' => 'NOT IN',
        '!IN' => 'NOT IN'
    ];

    public function __construct(protected Query $query, protected array $where)
    {
    }

    public function getSQL()
    {
        return $this->whereHandel($this->where);
    }

    /**
     * whereHandel
     */
    protected function whereHandel(array $where, string $relation = 'AND')
    {
        $sqls = [];
        foreach ($where as $u) {
            if (is_string($u)) {
                // 字符串
                $sqls[] = '( ' . $u . ' )';
            } else {
                // 数组
                $sql = [];
                foreach ($u as $k => $v) {
                    if (!\is_int($k)) {
                        // key=>value
                        if (null === $v) {
                            $sql[] = $k . ' IS NULL';
                        } elseif (is_string($v) || is_numeric($v) || is_bool($v)) {
                            $sql[] = $this->whereAnalysis($k, '=', $v);
                        } elseif (preg_match("/^(AND|OR)(\s+#.*)?$/", $k, $match)) {
                            $sql[] = $this->whereHandel($v, $match[1]);
                        } elseif (is_array($v)) {
                            $sql[] = $this->whereAnalysis($k, 'IN', $v);
                        } else {
                            trigger_error('Query where param(value) must string or array!');
                        }
                    } elseif (is_array($v)) {
                        $sql[] = $this->whereAnalysis(...$v);
                    } else {
                        $sql[] = (string) $v;
                    }
                }
                // AND 关联
                if (!empty($sql)) {
                    $sqls[] = '( ' . \implode(' ) AND ( ', $sql) . ' )';
                }
            }
        }

        // 无
        if (empty($sqls)) {
            return null;
        }
        // 合并
        return \implode(' ' . $relation . ' ', $sqls);
    }

    /**
     * 条件分析
     */
    protected function whereAnalysis(string $field, string $connector, mixed $value = null, array|string|null $replace = null)
    {
        // 替换
        if (null !== $replace) {
            // 复杂替换
            $replace = (array) $replace;
            $t = [];
            foreach ($replace as $u) {
                $t[] = Query::replaceColumnName($u);
            }
            $field = str_replace($replace, $t, $field);
        } else {
            // 简单
            $field = Query::replaceColumnName($field);
        }
        // 几种组合形式
        if (null === $value) {
            // 直接连接
            $sql = $field . ' ' . $connector;
        } elseif (array_key_exists($connector, self::CONNECTOR)) {
            // 常规
            $n1 = $this->query->createNamedParameter($value);
            $sql = $field . ' ' . self::CONNECTOR[$connector] . ' ' . $n1;
        } elseif (array_key_exists($connector, self::CONNECTOR_BETWEEN)) {
            // BETWEEN
            $connector = self::CONNECTOR_BETWEEN[$connector];
            if (is_array($value) && isset($value[1])) {
                $n1 = $this->query->createNamedParameter($value[0]);
                $n2 = $this->query->createNamedParameter($value[1]);
                $sql = $field . ' ' . $connector . ' ' . $n1 . ' AND ' . $n2;
            } else {
                $sql = $field . ' ' . $connector . ' ' . $value;
            }
        } elseif (array_key_exists($connector, self::CONNECTOR_IN)) {
            // in
            $connector = self::CONNECTOR_IN[$connector];
            if (is_array($value)) {
                $n1 = $this->query->createNamedParameter($value);
                $sql = $field . ' ' . $connector . ' (' . $n1 . ')';
            } else {
                $sql = $field . ' ' . $connector . ' (' . $value . ')';
            }
        } else {
            trigger_error(message: 'Query where connetor(' . $connector . ') is not defiened!');
        }
        // 完成
        return $sql;
    }
}
