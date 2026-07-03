<?php

namespace NilDB\Instance;

/**可缓存 */
trait InstanceCacheTrait
{
    protected static array $instanceCache = [];

    public static function factoryByID(int $id): static|false
    {
        return static::$instanceCache[$id] ??= parent::factoryByID($id);
    }
}
