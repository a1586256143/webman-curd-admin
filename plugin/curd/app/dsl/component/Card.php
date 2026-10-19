<?php
namespace plugin\curd\app\dsl\component;

/**
 * 键值卡片组件（弹窗 / 行内展开里用）
 *
 * 用法：
 *   new Card(['订单号' => 'A001', '金额' => 100], '订单详情')
 *
 *   // 也可以细配：html => true 强制按 HTML 渲染、false 强制文本；
 *   // 都不写时前端自动检测（值含真实 HTML 标签如 <span>/<b> 才渲染，纯文本照常转义）
 *   new Card([
 *       ['label' => '订单号', 'value' => 'A001'],
 *       ['label' => '状态',   'value' => '<span class="el-tag el-tag--success">已支付</span>'],
 *   ], '订单详情', ['column' => 2])
 *
 * options：column（每行几列，默认 1）/ border / size
 */
class Card extends Component
{
    protected array $items;
    protected string $title;
    protected array $options;

    /**
     * @param array  $items   ['标签' => '值'] 或 [['label'=>'..','value'=>'..','html'=>false], ...]
     * @param string $title   卡片标题
     * @param array  $options el-descriptions 属性（column / border / size）
     */
    public function __construct(array $items = [], string $title = '', array $options = [])
    {
        $this->items = $items;
        $this->title = $title;
        $this->options = $options;
    }

    public function toArray(): array
    {
        return array_merge([
            'type' => 'card',
            'title' => $this->title,
            'items' => $this->normalizeItems(),
        ], $this->options);
    }

    protected function normalizeItems(): array
    {
        $out = [];
        foreach ($this->items as $key => $value) {
            if (is_array($value) && (isset($value['label']) || isset($value['value']))) {
                $out[] = [
                    'label' => (string)($value['label'] ?? ''),
                    'value' => $this->stringify($value['value'] ?? ''),
                    // null = 未表态（前端自动检测：值含真实 HTML 标签才按 HTML 渲染）
                    'html' => isset($value['html']) ? (bool)$value['html'] : null,
                ];
                continue;
            }
            $out[] = [
                'label' => (string)$key,
                'value' => $this->stringify($value),
                'html' => null,
            ];
        }
        return $out;
    }

    protected function stringify($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
