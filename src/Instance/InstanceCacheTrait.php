<?php

namespace NilDB\Instance;

/**可缓存 */
trait InstanceCacheTrait
{
    protected static array $instanceCache = [];

    /**
     * 根据ID查询实例
     * 如果缓存中不存在该实例，或 $force_refresh 为 true，则从数据库查询并缓存结果
     * 
     * @param int $id 实例ID
     * @param bool $force_refresh 是否强制刷新缓存
     * @return static|false 实例或false
     */
    public static function factoryByID(int $id, bool $force_refresh = false): static|false
    {
        // 缓存查询结果，负结果（false）同样缓存：同一请求内不重复查库
        if ($force_refresh || !isset(static::$instanceCache[$id])) {
            static::$instanceCache[$id] = parent::factoryByID($id);
        }

        return static::$instanceCache[$id];
    }
}
