<?php

namespace NilDB\Helper;
use NilDB\Query;

/**
 * 查询SQL生成 Join
 */
final class QueryJoin
{
    public static function on(Query $query, string|array $on, string $fromAlias, string $join)
    {
        if (\is_string($on)) {
            return ["{$fromAlias}.{$on} = {$join}.{$on}"];
        }

        $parts = [];
        foreach ($on as $k => $v) {
            if (is_numeric($k)) {
                $parts[] = \is_string($v)
                    ? $v
                    : (new QueryWhere($query, [$v]))->getSQL();
            } elseif (str_contains($k, '.')) {
                // 已带别名的限定名，原样使用
                $parts[] = "{$k} = {$v}";
            } else {
                $a = Query::replaceColumnName("{$fromAlias}.{$k}");
                $b = Query::replaceColumnName("{$join}.{$v}");
                $parts[] = "{$a} = {$b}";
            }
        }

        return $parts;
    }
}
