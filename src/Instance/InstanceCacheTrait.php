<?php

namespace NilDB\Instance;

/**可缓存 */
trait InstanceCacheTrait
{
    protected static array $instanceCache = [];

    public static function factoryByID(int $id): static|false
    {
        // 缓存查询结果，负结果（false）同样缓存：同一请求内不重复查库
        return static::$instanceCache[$id] ??= parent::factoryByID($id);
    }
}
