<?php

namespace NilDB\Instance;

use NilDB\Sheet;

abstract class InstanceAbstract
{
    public const string FIELD_ID = 'id';

    public const array FIELD_ARRAY = [];

    public const string FIELD_GET = '*';

    public readonly int $id;

    /**要处理的表 */
    abstract public static function getSheet(): Sheet;

    public static function factory(array $where): static|false
    {
        $row = static::getSheet()->fetchRow(static::FIELD_GET, $where);

        if (false === $row) {
            return false;
        }

        return new static($row);
    }

    public static function factoryByID(int $id): static|false
    {
        return static::factory([static::FIELD_ID => $id]);
    }

    /**
     * 实例信息
     */
    protected array $details;

    protected function __construct(array $details)
    {
        $this->resetRow($details);
    }

    /**初始化 */
    protected function init(array $details): array
    {
        return $details;
    }

    /**数据重置 */
    protected function resetRow(array $details)
    {
        if (!isset($this->id)) {
            $this->id = (int) $details[static::FIELD_ID];
        }
        unset($details[static::FIELD_ID]);

        // 数组字段
        foreach (static::FIELD_ARRAY as $field) {
            if (isset($details[$field]) && \is_string($details[$field])) {
                $details[$field] = \json_decode($details[$field], true);
            }
        }

        $this->details = $this->init($details);
    }

    /**获取一个字段 */
    public function get(string $name, $default = null)
    {
        return $this->details[$name] ?? $default;
    }

    /**获取一个字段 */
    public function getInner(string $name, string $key, $default = null)
    {
        return $this->details[$name][$key] ?? $default;
    }

    /**
     * 更新数据
     */
    public function update(array $set)
    {
        $set_new = $set;
        foreach (static::FIELD_ARRAY as $field) {
            if (isset($set_new[$field])) {
                $set_new[$field] = \json_encode($set_new[$field]);
            }
        }

        static::getSheet()->update($set_new, ['id' => $this->id]);

        $this->resetRow([...$this->details, ...$set]);
    }
}
