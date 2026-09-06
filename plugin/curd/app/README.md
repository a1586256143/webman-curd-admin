# CURD 配置类系统

> 当前插件形态（webman 应用插件）：
> - 命名空间为 `plugin\curd\app\...`，源码位于 `plugin/curd/app/`
> - 路由为自定义 `/api/*`（见 `plugin/curd/config/route.php`），并非官方应用插件默认的 `/app/curd/*`
> - 初始化通过 `plugin/curd/config/autoload.php` 加载 `app/bootstrap.php`
> - 下方旧示例为历史文档，命名空间/路径以实际代码为准

## 概述

通过 PHP 类定义 CURD 配置，前端通过接口动态获取配置，实现配置与代码分离。

## 文件结构

```
webman-admin-api/plugin/curd/
├── CurdConfig.php          # 配置基类
├── OrderCurdConfig.php     # 订单管理配置示例
├── UserCurdConfig.php      # 用户管理配置示例
├── CurdConfigRegistry.php  # 配置注册中心
└── bootstrap.php           # 自动注册引导
```

## 快速开始

### 1. 创建配置类

在 `webman-admin-api/plugin/curd/` 目录下创建新的配置类：

```php
<?php
namespace plugin\curd;

use plugin\curd\CurdConfig;

/**
 * 商品管理 CURD 配置
 */
class ProductCurdConfig extends CurdConfig
{
    /**
     * 配置唯一标识
     */
    public function getKey(): string
    {
        return 'product';
    }

    /**
     * 页面标题
     */
    public function getTitle(): string
    {
        return '商品管理';
    }

    /**
     * API 接口配置
     */
    public function getApi(): array
    {
        return [
            'list' => '/api/product/list',
            'add' => '/api/product/add',
            'update' => '/api/product/update',
            'delete' => '/api/product/delete',
            'export' => '/api/product/export',
        ];
    }

    /**
     * 表格列配置
     */
    public function getColumns(): array
    {
        return [
            // 基础列
            $this->createColumn('id', 'ID', ['width' => 80, 'align' => 'center']),
            $this->createColumn('name', '商品名称'),
            $this->createColumn('price', '价格', ['width' => 100]),
            $this->createColumn('stock', '库存', ['width' => 100]),
            
            // 状态列（标签显示）
            $this->createColumn('status', '状态', [
                'type' => 'tag',
                // 自定义映射在 form 中定义
            ]),
            
            // 时间列
            $this->createColumn('created_at', '创建时间', [
                'type' => 'datetime',
                'width' => 180
            ]),
            
            // 开关列
            $this->createColumn('is_hot', '热门', [
                'type' => 'switch',
                'activeValue' => 1,
                'inactiveValue' => 0,
            ]),
        ];
    }

    /**
     * 搜索配置
     */
    public function getSearch(): array
    {
        return [
            // 文本搜索
            $this->createSearch('name', '商品名称', 'input'),
            $this->createSearch('barcode', '商品条码', 'input'),
            
            // 状态下拉搜索
            $this->createStatusSearch('status', '状态', [
                0 => '下架',
                1 => '上架',
            ]),
            
            // 日期范围搜索
            $this->createDateRangeSearch('created_at', '创建时间'),
        ];
    }

    /**
     * 表单字段配置（新增/编辑）
     */
    public function getFormFields(): array
    {
        return [
            $this->createFormField('name', '商品名称', 'input', [
                'rules' => ['required' => true, 'message' => '请输入商品名称']
            ]),
            $this->createFormField('price', '价格', 'number', [
                'min' => 0,
                'precision' => 2
            ]),
            $this->createFormField('stock', '库存', 'number', [
                'min' => 0
            ]),
            $this->createFormField('description', '商品描述', 'textarea', [
                'rows' => 4
            ]),
            $this->createFormField('status', '状态', 'radio', [
                'options' => $this->createDictOptions([
                    0 => '下架',
                    1 => '上架',
                ]),
                'defaultValue' => 1
            ]),
        ];
    }

    /**
     * 功能开关
     */
    public function getOptions(): array
    {
        return [
            'add' => true,          // 显示新增按钮
            'edit' => true,         // 显示编辑按钮
            'delete' => true,       // 显示删除按钮
            'view' => true,         // 显示查看按钮
            'export' => true,       // 显示导出按钮
            'batchDelete' => true,  // 显示批量删除
            'viewTitle' => '商品详情',  // 查看弹窗标题
        ];
    }

    /**
     * 弹窗宽度
     */
    public function getDialogWidth(): string
    {
        return '600px';
    }

    public function getViewDialogWidth(): string
    {
        return '800px';
    }
}
```

### 2. 注册配置

在 `webman-admin-api/plugin/curd/bootstrap.php` 中添加注册：

```php
<?php
use plugin\curd\CurdConfigRegistry;

// 注册订单管理
CurdConfigRegistry::register('order', \plugin\curd\OrderCurdConfig::class);

// 注册用户管理
CurdConfigRegistry::register('user', \plugin\curd\UserCurdConfig::class);

// 注册商品管理（新增）
CurdConfigRegistry::register('product', \plugin\curd\ProductCurdConfig::class);
```

### 3. 前端访问

访问地址：`/dynamic?key=product`

## 基类方法参考

### createColumn() - 创建列配置

```php
$this->createColumn(string $prop, string $label, array $options = [])

// 基础用法
$this->createColumn('id', 'ID')

// 带选项
$this->createColumn('status', '状态', [
    'width' => 100,
    'align' => 'center',
    'type' => 'tag',           // 标签类型
    'switch' => true,          // 开关类型
    'image' => true,           // 图片类型
    'datetime' => true,        // 时间类型
    'activeValue' => 1,        // 开关开启值
    'inactiveValue' => 0,      // 开关关闭值
])
```

### createSearch() - 创建搜索配置

```php
$this->createSearch(string $prop, string $label, string $type = 'input', array $options = [])

// 类型：input, select, number, date, datetime, daterange
$this->createSearch('name', '名称', 'input')
$this->createSearch('status', '状态', 'select', [
    'options' => [
        ['label' => '全部', 'value' => ''],
        ['label' => '启用', 'value' => 1],
        ['label' => '禁用', 'value' => 0],
    ]
])
```

### createFormField() - 创建表单字段

```php
$this->createFormField(string $prop, string $label, string $type = 'input', array $options = [])

// 类型：input, number, textarea, select, radio, switch, date, datetime, time
$this->createFormField('name', '名称', 'input')
$this->createFormField('content', '内容', 'textarea', ['rows' => 4])
$this->createFormField('status', '状态', 'radio', [
    'options' => $this->createDictOptions([0 => '禁用', 1 => '启用']),
    'defaultValue' => 1
])
```

### createDictOptions() - 创建字典选项

```php
$this->createDictOptions(array $map, bool $hasAll = false)

// 简单映射
$this->createDictOptions([0 => '禁用', 1 => '启用'])
// 结果: [['label' => '禁用', 'value' => 0], ['label' => '启用', 'value' => 1]]

// 包含"全部"选项
$this->createDictOptions([0 => '禁用', 1 => '启用'], true)
// 结果: [['label' => '全部', 'value' => ''], ...]
```

### 快捷方法

```php
// 状态搜索（自动包含"全部"选项）
$this->createStatusSearch('status', '状态', [0 => '禁用', 1 => '启用'])

// 日期范围搜索
$this->createDateRangeSearch('created_at', '创建时间')
```

## API 接口

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/curd/config/list` | GET | 获取所有已注册配置 |
| `/api/curd/config?key=xxx` | GET | 获取单个配置 |
| `/api/curd/config/all` | GET | 获取所有配置详情 |
| `/api/curd/tables` | GET | 获取数据库所有表 |
| `/api/curd/generate?table=xxx` | GET | 从数据库表生成配置 |

## 前端路由

| 路径 | 说明 |
|------|------|
| `/dynamic?key=order` | 通过 key 访问 |
| `/order` | 直接通过路径访问（如果没有配置类则从数据库生成）|

## 复制到其他电脑

1. **复制后端文件**
   - 复制整个 `webman-admin-api/plugin/curd/` 目录

2. **检查配置**
   - 确保 `config/autoload.php` 包含 `bootstrap.php`：
   ```php
   base_path() . '/plugin/curd/bootstrap.php',
   ```

3. **重启服务**
   ```bash
   cd webman-admin-api
   php start.php start
   ```

## 现有配置类

| Key | 标题 | 说明 |
|-----|------|------|
| `order` | 订单管理 | 完整示例，包含自定义渲染 |
| `user` | 用户管理 | 包含状态开关、图片列 |
