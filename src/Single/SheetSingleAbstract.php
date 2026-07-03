<?php
namespace NilDB\Single;

use NilDB\Data;
use NilDB\Sheet;

/**
 * 单例sheet
 */
abstract class SheetSingleAbstract extends Sheet
{
    /**
     * 表单名
     */
    public const string SHEET_NAME = '';

    public function __construct(Data $data)
    {
        parent::__construct($data, $this->getSheetName());
    }

    /**
     * 获取数据表真实的名
     */
    protected function getSheetName()
    {
        return static::SHEET_NAME;
    }
}