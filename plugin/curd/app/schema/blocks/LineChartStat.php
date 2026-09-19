<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 折线图统计卡（LineChartStat）→ 前端 LineChartStatNode
 *
 * 「卡片标题 + 子标题 + 渐变面积折线图」的组合块：平滑折线、峰值气泡标注、
 * 平均值虚线、x/y 网格，即经典后台首页的业绩趋势图。
 *
 * 用法：
 *   $col->lineChartStat('网站数据')
 *       ->subTitle('当月业绩折线图')
 *       ->data('{{stats.trend}}')          // 数值数组，占位符整串注入数组
 *       ->categories('{{stats.days}}');    // x 轴类目，省略则用 1..n
 *
 * data / categories 支持两种写法：
 *   ① {{key.path}} 占位符 —— dataApi 里是数组时整串注入（推荐，数据驱动）；
 *   ② 直接传 PHP 数组 —— 适合静态演示数据，会原样进 JSON。
 *
 * 图表库为 echarts（前端按需注册 LineChart/Grid/MarkPoint/MarkLine/Canvas），
 * 不引入全量 echarts，新增本块不会显著增大产物。
 */
class LineChartStat extends SchemaNode
{
    public function __construct()
    {
        $this->type = 'line-chart-stat';
    }

    /** 卡片标题（el-card header，如「网站数据」） */
    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /** 子标题（图表上方的高亮小字，如「当月业绩折线图」） */
    public function subTitle(string $subTitle): static
    {
        return $this->set('subTitle', $subTitle);
    }

    /**
     * 数值序列（y 轴数据）
     *
     * @param array|string $data 数值数组，或 '{{stats.trend}}' 形式的占位符
     */
    public function data($data): static
    {
        return $this->set('data', $data);
    }

    /**
     * x 轴类目
     *
     * @param array|string $categories 类目数组，或 '{{stats.days}}' 占位符；省略则自动用 1..n
     */
    public function categories($categories): static
    {
        return $this->set('categories', $categories);
    }

    /** 系列名（图例 / tooltip 用，默认「业绩」） */
    public function seriesName(string $seriesName): static
    {
        return $this->set('seriesName', $seriesName);
    }

    /** 主色（折线 + 面积渐变 + 峰值气泡，默认 #36cfc9） */
    public function color(string $color): static
    {
        return $this->set('color', $color);
    }

    /** 图表高度（px，默认 350） */
    public function height(int $height): static
    {
        return $this->set('height', $height);
    }

    /** 平滑曲线（默认 true；false 为折线） */
    public function smooth(bool $smooth = true): static
    {
        return $this->set('smooth', $smooth);
    }

    /** 峰值气泡标注（默认 true） */
    public function showMax(bool $showMax = true): static
    {
        return $this->set('showMax', $showMax);
    }

    /** 平均值虚线（默认 true） */
    public function showAverage(bool $showAverage = true): static
    {
        return $this->set('showAverage', $showAverage);
    }

    /** 渐变面积填充（默认 true） */
    public function areaGradient(bool $areaGradient = true): static
    {
        return $this->set('areaGradient', $areaGradient);
    }

    /** y 轴数值单位后缀（如「次」，省略则纯数字） */
    public function unit(string $unit): static
    {
        return $this->set('unit', $unit);
    }
}
