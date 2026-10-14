<?php
namespace plugin\curd\app\validation;

/**
 * 轻量表单验证器（插件内置零依赖版）
 * 对标 Laravel validation 常用规则子集，零依赖。
 *
 * 插件为了让任意全新 webman 宿主（未自带 app\validation\Validator）也能直接
 * composer 安装使用，内置此校验器；若宿主已存在 app\validation\Validator 则优先用宿主版
 * （见 CurdActionsTrait::validateData 的回落逻辑），保证行为向后兼容。
 *
 * 规则写法（| 分隔，参数用 : 分隔）：
 *   required 必填
 *   max:N    最大长度/最大值
 *   min:N    最小长度/最小值
 *   integer  整数
 *   numeric  数字
 *   in:a,b,c 枚举（逗号分隔）
 *   email    邮箱格式
 *   between:N,M 长度/数值区间
 *   nullable 允许为空
 *   regex:xxx 正则（兼容 /pattern/ 与 pattern 两种写法）
 */
class Validator
{
    /**
     * @param array $data  待校验数据
     * @param array $rules  ['field' => 'required|integer|max:100', ...]
     * @return array 错误列表 ['field' => '错误消息', ...]，空数组表示通过
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleStr) {
            if (!is_string($ruleStr) || $ruleStr === '') {
                continue;
            }

            $parts = explode('|', $ruleStr);
            $value = $data[$field] ?? null;

            // nullable：空值直接跳过所有规则（除 required 单独处理）
            if (in_array('nullable', $parts) && ($value === null || $value === '')) {
                continue;
            }

            foreach ($parts as $rule) {
                // 解析 rule 和参数
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

                $check = self::check($name, $param, $value);
                if ($check !== true) {
                    $errors[$field] = $check ?: $field . ' 校验失败';
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * 单条规则校验
     */
    protected static function check(string $name, ?string $param, $value)
    {
        switch ($name) {
            case 'required':
                return ($value !== null && $value !== '') ? true : '该字段不能为空';

            case 'integer':
                if ($value === null || $value === '') return true;
                return filter_var($value, FILTER_VALIDATE_INT) !== false ? true : '必须是整数';

            case 'numeric':
                if ($value === null || $value === '') return true;
                return is_numeric($value) ? true : '必须是数字';

            case 'email':
                if ($value === null || $value === '') return true;
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? true : '邮箱格式不正确';

            case 'max':
                if ($value === null || $value === '') return true;
                $limit = (int)$param;
                if (is_numeric($value) && !preg_match('/^\d+$/', (string)$value)) {
                    return $value <= $limit ? true : "不能大于 {$limit}";
                }
                return mb_strlen((string)$value) <= $limit ? true : "长度不能超过 {$limit}";

            case 'min':
                if ($value === null || $value === '') return true;
                $limit = (int)$param;
                if (is_numeric($value)) {
                    return $value >= $limit ? true : "不能小于 {$limit}";
                }
                return mb_strlen((string)$value) >= $limit ? true : "长度不能小于 {$limit}";

            case 'between':
                if ($value === null || $value === '') return true;
                [$min, $max] = array_pad(explode(',', (string)$param), 2, null);
                $len = is_numeric($value) ? (float)$value : mb_strlen((string)$value);
                if ($len < (float)$min || $len > (float)$max) {
                    return "必须在 {$min} 和 {$max} 之间";
                }
                return true;

            case 'in':
                if ($value === null || $value === '') return true;
                $allowed = array_map('trim', explode(',', (string)$param));
                return in_array((string)$value, $allowed, true) ? true : '值不在允许范围内';

            case 'regex':
                if ($value === null || $value === '') return true;
                $pattern = (string)$param;
                // 兼容 /pattern/ 和 pattern 两种写法
                if (!preg_match('/^\/.*\/[a-z]*$/i', $pattern)) {
                    $pattern = '/' . $pattern . '/';
                }
                return preg_match($pattern, (string)$value) ? true : '格式不正确';

            default:
                return true;
        }
    }
}
