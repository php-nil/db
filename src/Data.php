<?php

namespace NilDB;

use Doctrine\DBAL\Connection;
use Nil\Kernel\Kernel;
use NilDB\Entity\Entities;
use const \Nil\Kernel\DEFAULT_NAME;

final class Data
{
    protected static $list = [];
    public static function get(?string $name = null): self
    {
        $name ??= DEFAULT_NAME;

        return static::$list[$name] ??= new Data($name);
    }

    /**
     * 数据库链接
     */
    public readonly Connection $connection;

    protected array $sheets = [];
    protected array $entities = [];

    protected function __construct(public readonly string $name)
    {
        $this->connection = Kernel::dbal($name);
    }

    /**
     * 创建查询
     */
    public function getQuery(?string $table = null): Query
    {
        return new Query($this, $table);
    }

    /**
     * 单表管理
     */
    public function sheet(string $table): Sheet
    {
        return $this->sheets[$table] ??= new Sheet($this, $table);
    }

    /**
     * 实体管理(多表)
     */
    public function entities(string $name = 'data'): Entities
    {
        return $this->entities[$name] ??= new Entities($this, $name);
    }
}
