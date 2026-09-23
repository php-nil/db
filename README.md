# Nil DB

Nil DB ON DBAL —— NIL 框架的数据访问组件，基于 Doctrine DBAL 4 封装，提供单表快捷操作、链式查询构建器与多表分表实体能力。

## 依赖

- PHP >= 8.5.0
- doctrine/dbal ^4.4
- php-nil/kernel ^3.0（连接与 PSR-6 缓存由内核提供）

## 安装

```bash
composer require php-nil/db
```

## 快速上手

```php
use NilDB\Data;

$data  = Data::get();              // 默认连接；传名称可切换连接
$sheet = $data->sheet('users');    // 表对象可复用

$sheet->insertGetId(['name' => 'John']);
$sheet->update(['name' => 'Jane'], ['id' => 1]);
$sheet->delete(['id' => 1]);

$row  = $sheet->fetchRow(null, ['id' => 1]);           // 单行（第1参为列，第2参为条件）
$list = $sheet->fetchAll(['id', 'name'], ['status' => 1], 10, ['id' => 'DESC']);
$id   = $sheet->fetchOne('id', ['name' => 'John']);    // 单值

$sheet->count(['status' => 1]);
$sheet->sum(null, 'amount');                           // 另提供 max/min/avg/countDistinct
```

## 查询构建器

```php
$data->getQuery('users')
    ->column('id', 'name')
    ->where(['status' => 1, 'id' => [1, 2, 3]])        // 数组自动参数化；支持 IN/BETWEEN/IS NULL/嵌套 OR
    ->group('dept', 'COUNT(*) > 5')                     // 分组 + HAVING
    ->order(['id' => 'DESC'])
    ->limit(10, 20)                                     // limit, offset
    ->fetchAll();

// 自定义排序：值以绑定参数传递，按给定顺序 [3, 1, 2] 排列，未命中行排最前
// MySQL/MariaDB 生成 FIELD(id, ?, ?, ?)；PostgreSQL/SQLite 等自动生成等价 CASE WHEN
$query->order(['id' => [3, 1, 2]]);
```

联表支持 inner / left / right，可链式指定 `fromAlias`：

```php
// 写法一：链式
$data->getQuery()
    ->from('orders', 'o')
    ->leftJoin('users', 'u', ['u.id' => 'o.user_id'])
    ->column('o.id', 'u.name');

// 写法二：from() 第3参声明联表、第4参声明连接类型（默认 inner）
$data->getQuery()
    ->from('orders', 'o', [
        'u' => ['users', 'u.id', 'o.user_id'],          // 别名 => [表, 左字段, 右字段]
    ], 'left');
```

## 批量写入

均使用位置参数绑定，行间字段自动取并集、缺失列补 `NULL`；入参非法时抛 `InvalidArgumentException`。

```php
$sheet->insertMany([
    ['name' => 'a', 'age' => 1],
    ['name' => 'b'],                                    // age 自动补 NULL
]);

// 以主键值为键，一条 SQL 的 CASE WHEN 完成差异化更新
$sheet->updateMany('id', [
    1 => ['name' => 'a'],
    2 => ['name' => 'b', 'age' => 3],
]);
```

## 事务

闭包内抛异常自动回滚，正常结束提交，并透传闭包返回值：

```php
$data->transaction(function () use ($sheet) {
    $sheet->insert(['name' => 'a']);
    $sheet->insert(['name' => 'b']);
});
```

实体（`Entity`）的主附表写入、更新、删除内部已包在同一事务中。

## 实体（多表分表）

一个实体可按字段拆分到主表与多个附表：查询自动以主表 LEFT JOIN 附表（仅有主表记录也可见），写入按字段路由到各表，配置经内核 PSR-6 缓存。

```php
$entities = $data->entities();

$creater = new EntityCreater();
$creater->addColumn('name', 'string');                       // 默认位于主表
$creater->addColumn('content', 'text', 'info');              // 拆到 info 附表
$creater->addIndex(['name']);
[$id, $options] = $entities->createEntity('goods', $creater);// 元数据与建表原子提交

$goods = $entities->getEntityByName('goods');
$goods->insert(['name' => 'x', 'content' => 'y']);           // 未知字段抛 InvalidArgumentException
$goods->fetchAll(['name', 'content']);
```

## 安全约定

- 所有值均经 DBAL 参数绑定，标识符按驱动规则转义；FIELD 排序值同样参数化。
- 非法查询输入（未知连接器、错误的 BETWEEN/IN 结构、未定义字段等）抛异常而非静默拼错 SQL。
- 字段名替换器为全局状态，异常时保证复位，不会污染后续查询。

## 变更记录

### 2026-09-23 DBAL 弃用 API 替换与测试覆盖率 100%

- 消除 DBAL 4 弃用调用：`Table::setPrimaryKey()` 已被标记弃用，
  `EntityCreater` 与 `Option` 的建表逻辑统一改为
  `addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...)->create())`。
  反射扫描全部已调用 DBAL API，除该方法外无其他弃用项。
- 测试套件由 27 用例/驱动扩充至 **45 用例/驱动（三驱动共 135 用例、531 断言）**，
  在 Xdebug 覆盖率下达到 **类 15/15、方法 128/128、行 707/707 全部 100%**；
  `Sheet::updateMany()` 中两处经论证不可达的防御性分支以 `@codeCoverageIgnore` 标注。

### 2026-09-23 PHPUnit 三驱动测试（pdo_sqlite / pdo_pgsql / pdo_mysql）修复

- 修复 `Query::select()` 不传列（`fetchRow/fetchAll` 列参数为 null）时在 DBAL 4 下
  抛出 "No SELECT expressions given" 的问题：缺省按 `SELECT *` 处理。
- 修复 `Query::column()` 字符串列名不经过字段名替换器的问题：实体以逻辑列名查询时，
  生成的 SQL 在 PostgreSQL 上直接报 undefined column；现字符串列同样替换，
  且发生替换时自动附加逻辑列名别名，结果集键名保持为调用方传入的列名。
- 自定义排序跨方言化：MySQL/MariaDB 继续使用原生 `FIELD()`；PostgreSQL/SQLite 等
  其他平台自动生成等价的标准 SQL
  `CASE WHEN col = ? THEN 1 ... ELSE 0 END`（未命中排最前，与 FIELD 语义一致），
  值同样全部参数化绑定。
- 修复 MySQL 建实体兼容：实体字段声明为 `string` 但未指定长度时，MySQL 要求 VARCHAR
  必须显式长度，现统一补默认长度 255（与 PostgreSQL/SQLite 的平台默认值一致）。
- 修复 MySQL 建实体事务问题：MySQL 的 DDL 隐式提交事务，DDL 后 DBAL 统一 COMMIT
  会抛 `NoActiveTransaction`；现 MySQL/MariaDB 平台建实体不再包裹事务
  （DDL 本就无法回滚；SQLite/PostgreSQL 仍保持事务原子性）。

### 2026-09-23 审计修复

- 修复链式联表第二跳起错误使用主表别名的问题；`from()` 新增第4参 `joinType`（inner/left/right，默认值保持向后兼容）。
- 修复联表 ON 条件中含 `.` 限定名判断失效的问题。
- FIELD() 自定义排序值改为参数绑定，消除 SQL 注入面。
- WHERE 构造的各类非法输入由 `trigger_error`/未定义变量改为抛 `InvalidArgumentException`。
- 新增 `Data::transaction(callable)`；实体的建实体、插入、更新、删除均纳入事务。
- 修复实体配置缓存只 set 不 save 导致永不落盘的问题；配置变更时显式删除缓存项；json_decode 增加空值防护。
- 实体查询主附表改为 LEFT JOIN；写入前校验字段；`updateByID/deleteByID` 返回全部相关表受影响行数。
- `insertMany/updateMany` 改为位置参数绑定，消除命名参数与字段名耦合带来的冲突隐患。
- 实例缓存（`InstanceCacheTrait`）对查询结果缓存，包含 `false` 负结果：同一请求内不重复查库。
- `EntityCreater::addIndex()` 增加空字段与跨分表校验；清理死属性、错字命名与悬空注释。
- composer.json 补充 `php-nil/kernel ^3.0` 依赖声明。

## License

MIT
