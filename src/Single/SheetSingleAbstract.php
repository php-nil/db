<?php
namespace NilDB\Single;

use NilDB\Data;
use NilDB\Sheet;

/**
 * 单例sheet
 */
abstract class SheetSingleAbstract extends Sheet implements SingleInterface
{
    public function __construct(Data $data)
    {
        parent::__construct($data, $this->getSheetName());
    }

    /**
     * 获取数据表真实的名称
     * 便于子类重写
     */
    protected function getSheetName()
    {
        return static::SHEET_NAME;
    }
}