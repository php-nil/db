<?php
namespace NilDB\Single;

use NilDB\Data;
use NilDB\Sheet;
use const \Nil\Kernel\DEFAULT_NAME;

/**
 * 单表工厂
 */
abstract class SheetFactoryAbstract extends Sheet implements SingleInterface
{
    /**
     * 数据库链接名
     */
    public const string DBAL_NAME = DEFAULT_NAME;

    /**
     * 单例实例
     */
    private static array $_instances = [];

    /**
     * 获取sheet
     */
    public static function sheet(): static
    {
        return self::$_instances[static::class] ??= new static(Data::get(static::DBAL_NAME), static::getSheetName());
    }

    /**
     * 获取数据表真实的名称
     * 便于子类重写
     */
    protected static function getSheetName(): string
    {
        return static::SHEET_NAME;
    }
}