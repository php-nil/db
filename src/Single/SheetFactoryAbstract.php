<?php
namespace NilDB\Single;

use NilDB\Data;
use NilDB\Sheet;
use const \Nil\Kernel\DEFAULT_NAME;

/**
 * 单表工厂
 */
abstract class SheetFactoryAbstract extends Sheet
{
    /**
     * 表单名
     */
    abstract public const string SHEET_NAME = '';

    /**
     * 数据库链接名
     */
    public const string DBAL_NAME = DEFAULT_NAME;

    /**
     * 单例实例
     */
    protected static object $instance;

    /**
     * 获取sheet
     */
    public static function sheet(): static
    {
        return static::$instance ??= new static(Data::get(static::DBAL_NAME), static::SHEET_NAME);
    }
}