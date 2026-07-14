<?php

namespace NilDB\Entity;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Nil\Kernel\Kernel;
use Nil\Nil;
use NilDB\Sheet;
use Psr\Cache\CacheItemInterface;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * 配置 管理
 */
class Option
{
    public readonly Sheet $sheet;

    protected string $cacheName;
    protected CacheItemInterface $cacheItem;
    protected array $cacheData;
    protected bool $isCacheChanged = false;

    public function __construct(protected Entities $entities)
    {
        $this->sheet = $entities->data->sheet(
            $entities->getRealTable(Definition::TABLE_OPT)
        );

        $this->cacheName = 'NilDBEntity_' . $entities->data->name . '_' . $entities->name;
    }

    /**
     * 获取options 配置
     * @param string $type
     * @param string $name
     * @param int $relation
     * 
     * @return array 配置数组
     */
    public function getOption(string $type, string $name, int $relation = 0)
    {
        $options = $this->sheet->fetchOne(['options'], [
            'type' => $type,
            'name' => $name,
            'relation' => $relation
        ]);
        if (false === $options) {
            return [];
        }

        return \json_decode($options, true);
    }

    protected function getCacheItem()
    {
        if (!isset($this->cacheItem)) {
            $this->cacheItem = Kernel::cache()->getItem($this->cacheName);
        }
        return $this->cacheItem;
    }

    public function getCache()
    {
        // 已经定义了
        if (isset($this->cacheData)) {
            return $this->cacheData;
        }

        $item = $this->getCacheItem();
        if ($item->isHit()) {
            $this->cacheData = $item->get();
        } else {
            $this->cacheData = [];
        }

        return $this->cacheData;
    }

    public function clearCache()
    {
        $this->cacheData = [];
        $this->getCacheItem()->set(null);
    }

    public function listByTypeIfCache(string $type, int $relation = 0)
    {
        if (!Nil::debug()) {
            $name = $type . $relation;
            $cache = $this->getCache();
            if (isset($cache[$name])) {
                return $cache[$name];
            } else {
                $data = $this->listByType($type, $relation);
                $this->cacheData[$name] = $data;
                $this->isCacheChanged = true;
                return $data;
            }
        } else {
            return $this->listByType($type, $relation);
        }
    }

    // 获取
    public function listByType($type, int $relation = 0)
    {
        $co = ['name', 'id', 'options'];
        $wh = [
            'type' => $type,
            'relation' => $relation
        ];

        try {
            $list = $this->sheet->fetchAll($co, $wh);
        } catch (TableNotFoundException $th) {
            $this->createTable();
            $list = $this->sheet->fetchAll($co, $wh);
        }

        foreach ($list as &$row) {
            $row['options'] = \json_decode($row['options'], true);
        }

        return $list;
    }

    /**
     * 更新属性
     */
    public function updateOption(int $id, array $options)
    {
        // 清除缓存
        $this->cacheData = [];
        $this->isCacheChanged = true;

        return $this->sheet->update(
            ['options' => \json_encode($options)],
            ['id' => $id]
        );
    }
    /**
     * 更新名称
     */
    public function updateName(int $id, string $name)
    {
        // 清除缓存
        $this->cacheData = [];
        $this->isCacheChanged = true;

        return $this->sheet->update(
            ['name' => $name],
            ['id' => $id]
        );
    }

    /**
     * 创建配置表
     * @return void
     */
    protected function createTable()
    {
        $tbname = $this->sheet->table;
        $schema = new Schema();
        $myTable = $schema->createTable($tbname);
        $myTable->addColumn("id", "bigint", ["unsigned" => true, 'autoincrement' => true]);
        $myTable->addColumn("type", "string", ["length" => 85]);
        $myTable->addColumn("name", "string", ["length" => 85]);
        $myTable->addColumn("relation", "bigint", ["unsigned" => true, "default" => 0]);
        $myTable->addColumn("options", "text");
        $myTable->addColumn("time_add", "datetime", []);

        $myTable->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create()
        );
        $myTable->addIndex(['type']);
        $myTable->addIndex(['relation']);
        $myTable->setComment('tasks');

        $conn = $this->entities->data->connection;
        $queries = $schema->toSql($conn->getDatabasePlatform());

        foreach ($queries as $q) {
            $conn->executeStatement($q);
        }
    }

    /**
     * 添加配置
     */
    public function add($type, $name, $relation = 0, array $options = [])
    {
        $data = [
            'name' => $name,
            'type' => $type,
            'relation' => $relation
        ];
        try {
            $id = $this->sheet->fetchOne('id', $data);
        } catch (TableNotFoundException $th) {
            $this->createTable();
            $id = $this->sheet->fetchOne('id', $data);
        }
        if (false !== $id) {
            throw new \Error("已经存在");
        }

        $data['options'] = \json_encode($options);
        $data['time_add'] = \date('Y-m-d H:i:s');

        // 缓存状态
        $this->cacheData = [];
        $this->isCacheChanged = true;

        return $this->sheet->insertGetId($data);
    }

    /**
     * 缓存落盘策略问题
     */
    public function __destruct()
    {
        if ($this->isCacheChanged) {
            $item = $this->getCacheItem();
            $item->set(
                empty($this->cacheData) ? null : $this->cacheData
            );
        }
    }
}
