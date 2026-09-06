<?php
namespace plugin\curd\app\rbac;

use Casbin\Model\Model;
use Casbin\Persist\Adapter;
use plugin\curd\app\CurdDb;
use support\Redis;

/**
 * casbin 数据库适配器（插件内置版）
 * 规则存认证库(admin_connection) casbin_rule 表,与业务库解耦
 *
 * casbin_rule 表结构:
 *   id INT PK AI
 *   ptype VARCHAR(20)   -- p / g
 *   v0 VARCHAR(100)
 *   v1 VARCHAR(100)
 *   v2 VARCHAR(100)
 *   v3 VARCHAR(100)
 *   v4 VARCHAR(100)
 *   v5 VARCHAR(100)
 *
 * 多进程同步：每次**写库成功**后递增 Redis 版本号 casbin:policy:version，
 * Rbac::enforce() 前比对版本号，不一致则 loadPolicy() 重载。
 * Redis 不可用时降级跳过（各进程按各自内存策略工作，重启后一致）。
 */
class DatabaseAdapter implements Adapter
{
    /**
     * 策略版本号 Redis key（与 Rbac 共用常量）
     */
    public const POLICY_VERSION_KEY = 'casbin:policy:version';

    protected function table()
    {
        return CurdDb::adminTable('casbin_rule');
    }

    /**
     * 写库成功后递增策略版本号（Redis 不可用忽略，不影响写库本身）
     */
    protected function bumpPolicyVersion(): void
    {
        try {
            Redis::incr(self::POLICY_VERSION_KEY);
        } catch (\Throwable $e) {
            // Redis 挂：跳过，各进程靠重启对齐
        }
    }

    public function loadPolicy(Model $model): void
    {
        $rows = $this->table()->get();
        foreach ($rows as $row) {
            $this->loadPolicyLine((array)$row, $model);
        }
    }

    protected function loadPolicyLine(array $line, Model $model): void
    {
        $ptype = $line['ptype'] ?? '';
        if (!$ptype) {
            return;
        }
        $values = [];
        for ($i = 0; $i < 6; $i++) {
            $key = 'v' . $i;
            if (isset($line[$key]) && $line[$key] !== null && $line[$key] !== '') {
                $values[] = $line[$key];
            } else {
                break;
            }
        }
        if (empty($values)) {
            return;
        }
        // p 类型规则 sec=p, g 类型规则(角色继承) sec=g
        $sec = str_starts_with($ptype, 'g') ? 'g' : 'p';
        $model->addPolicy($sec, $ptype, $values);
    }

    public function savePolicy(Model $model): void
    {
        $this->table()->delete();
        // 通过反射读取 model 的 items（sec => [ptype => assertion]）
        $ref = new \ReflectionProperty($model, 'items');
        $ref->setAccessible(true);
        $items = $ref->getValue($model);

        foreach ($items as $sec => $assertions) {
            foreach ($assertions as $ptype => $assertion) {
                foreach ($assertion->policy as $rule) {
                    $this->table()->insert($this->toRow($ptype, $rule));
                }
            }
        }
        $this->bumpPolicyVersion();
    }

    public function addPolicy(string $sec, string $ptype, array $rule): void
    {
        $this->table()->insert($this->toRow($ptype, $rule));
        $this->bumpPolicyVersion();
    }

    public function removePolicy(string $sec, string $ptype, array $rule): void
    {
        $query = $this->table()->where('ptype', $ptype);
        foreach ($rule as $i => $value) {
            $query->where('v' . $i, $value);
        }
        $query->delete();
        $this->bumpPolicyVersion();
    }

    public function removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, string ...$fieldValues): void
    {
        $query = $this->table()->where('ptype', $ptype);
        foreach ($fieldValues as $i => $value) {
            if ($value !== '') {
                $query->where('v' . ($fieldIndex + $i), $value);
            }
        }
        $query->delete();
        $this->bumpPolicyVersion();
    }

    protected function toRow(string $ptype, array $rule): array
    {
        $row = ['ptype' => $ptype];
        for ($i = 0; $i < 6; $i++) {
            $row['v' . $i] = $rule[$i] ?? '';
        }
        return $row;
    }
}
