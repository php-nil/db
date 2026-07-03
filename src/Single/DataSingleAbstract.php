<?php
namespace NilDB\Single;

use NilDB\Data;
use NilDB\Sheet;
use const \Nil\Kernel\DEFAULT_NAME;

/**
 * 单例全局调用
 */
abstract class DataSingleAbstract
{
    /**
     * 数据库名称
     */
    public const string DB_NAME = DEFAULT_NAME;

    protected static array $map = [];

    protected static function getSheet(string $class): Sheet
    {
        return static::$map[$class] ??= new $class(Data::get(static::DB_NAME));
    }
}
