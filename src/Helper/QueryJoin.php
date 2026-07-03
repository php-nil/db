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
            } elseif (strpos(".", $k) !== false) {
                // 有标记
                $parts[] = "{$k} = {$v}";
            } else {
                if (false === strpos(".", $k)) {
                    $a = "{$fromAlias}.{$k}";
                    $b = "{$join}.{$v}";
                } else {
                    $a = $k;
                    $b = $v;
                }
                
                $a = Query::replaceColumnName($a);
                $b = Query::replaceColumnName($b);
                $parts[] = "{$a} = {$b}";
            }
        }

        return $parts;
    }
}
