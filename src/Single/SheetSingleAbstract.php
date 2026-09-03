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
        parent::__construct($data, static::SHEET_NAME);
    }
}