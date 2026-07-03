# Nil DB

Nil DB ON DBAL - NIL framework component

基于 Doctrine DBAL 构建的轻量级数据库操作组件，提供简洁的查询构建和单表操作接口。

## 安装

```bash
composer require php-nil/db
```

## 依赖

- PHP >= 8.5.0
- Doctrine DBAL ^4.4

## 使用

### 快速开始

```php
use NilDB\Data;

// 获取数据库实例
$data = Data::get();

// 获取单表操作对象
$sheet = $data->sheet('users');

// 查询数据
$users = $sheet->fetchAll();

// 获取单行
$user = $sheet->fetchRow(['id' => 1]);

// 插入数据
$id = $sheet->insertGetId(['name' => 'John']);

// 更新数据
$sheet->update(['name' => 'Jane'], ['id' => 1]);

// 删除数据
$sheet->delete(['id' => 1]);

// 统计
$count = $sheet->count();
```

### 查询构建器

```php
use NilDB\Data;

$data = Data::get();
$query = $data->getQuery('users');

// 链式查询
$result = $query
    ->column('id', 'name', 'email')
    ->where(['status' => 1])
    ->order(['id' => 'DESC'])
    ->limit(10, 0)
    ->fetchAll();
```

### 聚合函数

```php
$count = $sheet->count(['status' => 1]);
$sum = $sheet->sum(['status' => 1], 'amount');
$max = $sheet->max(['status' => 1], 'score');
$min = $sheet->min(['status' => 1], 'score');
$avg = $sheet->avg(['status' => 1], 'score');
```

## 功能特性

- 基于 Doctrine DBAL，支持多种数据库
- 链式查询构建器
- 字段名替换支持
- 单表快速操作
- 聚合函数支持
- 参数化查询，防止 SQL 注入

## License

MIT
