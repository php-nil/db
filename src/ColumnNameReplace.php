<?php

namespace NilDB;

/**
 * 字段替换规则
 */
class ColumnNameReplace
{
    public static function init(array $map)
    {
        (new static($map))->setToQuery();
    }

    /**
     * 执行一个QUERY
     * 结束后清空
     */
    public static function run(array|self $map, callable $callable)
    {
        if (is_array($map)) {
            static::init($map);
        } else {
            $map->setToQuery();
        }

        try {
            return call_user_func($callable);
        } finally {
            // 回调异常时也必须复位，避免全局替换器污染后续查询
            Query::setColumnNameReplace(null);
        }
    }
    /**
     * 直接设置
     */
    public function setToQuery()
    {
        Query::setColumnNameReplace($this);
    }

    public function __construct(protected array $map)
    {
    }

    public function add(string $name, string $value)
    {
        $this->map[$name] = $value;
        return $this;
    }

    public function get(string $name)
    {
        return $this->map[$name] ?? null;
    }

    public function replace(string $name)
    {
        return $this->map[$name] ?? $name;
    }
}
