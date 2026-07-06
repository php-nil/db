<?php

namespace NilDB\Entity;

use NilDB\Data;

// 应用定义
class Entities
{
    /**
     * 配置表操作
     * @var Option
     */
    protected $option;

    /**
     * 实体清单
     * @var array
     */
    protected $entityMap;

    /**
     * 实体清单
     * @var array
     */
    protected $entityIDMap;

    /**
     * 实体清单
     */
    protected array $list = [];

    public function __construct(public readonly Data $data, public readonly string $name)
    {
        // 获取清单
        $this->option = new Option($this);

        // 所有实例
        $rets = $this->option->listByTypeIfCache('entity');

        // 两种 map
        $this->entityMap = array_column($rets, 'id', 'name');
        $this->entityIDMap = array_column($rets, 'name', 'id');

        // 定义list
        foreach ($rets as $la) {
            $this->list[$la['id']] = new Entity(
                $this,
                $la['id'],
                $la['name'],
                $la['options']
            );
        }
    }

    public function getOption(): Option
    {
        return $this->option;
    }

    public function getRealTable($name)
    {
        return $this->name . '_' . $name;
    }

    /**
     * 创建实体
     */
    public function createEntity(string $name, EntityCreater $entityCreater)
    {
        if ($this->hasEntityByName($name)) {
            throw new \Error("entity name exist");
        }

        [$id, $options] = $entityCreater->create($name, $this);

        $this->entityMap[$name] = $id;
        $this->entityIDMap[$id] = $name;

        return $this->list[$id] = new Entity(
            $this,
            $id,
            $name,
            $options
        );
    }

    /**
     * 获取实例 根据名称
     */
    public function getEntityByName(string $name)
    {
        // 不存在
        if (!isset($this->entityMap[$name])) {
            throw new \Error("不存在");
        }

        // 获取一个实例
        return $this->getEntity($this->entityMap[$name]);
    }

    /**
     * 获取实例 ID
     */
    public function getEntity(int $id)
    {
        // 已经存在
        if (isset($this->list[$id])) {
            return $this->list[$id];
        }

        throw new \Error("不存在");
    }
    /**
     * 实例是否存在 名称
     */
    public function hasEntityByName(string $name): bool
    {
        return isset($this->entityMap[$name]);
    }
    /**
     * 实例是否存在 ID
     */
    public function hasEntity(int $id): bool
    {
        return isset($this->entityIDMap[$id]);
    }
}
