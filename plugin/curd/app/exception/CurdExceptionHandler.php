<?php
namespace plugin\curd\app\exception;

use support\exception\Handler as BaseHandler;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * CURD 插件异常处理器（/api 接口的 SQL 报错等未捕获异常的统一出口）
 *
 * 为什么是「异常处理器」而不是中间件：
 *   webman 的中间件链在 App::getCallback() 里是 **每层各自 try/catch** 包起来的
 *   （`$callback = array_reduce($middlewares, fn => fn($request) { try { return $pipe(...) } catch { return static::exceptionResponse(...) } })`），
 *   再加上链尾 $innermost 也先 catch 一次。所以控制器/路由中间件抛出的异常，
 *   **在最内层就已经被转换成 Response**，外层中间件的 try/catch 永远捕获不到
 *   （2026-09-20 实测：按中间件方案实现后，线上模式仍返回框架默认的
 *    "Server internal error"，因为异常根本没走到中间件）。
 *   异常处理器是框架唯一指定的出口：App::exceptionResponse() →
 *   handler->report() → handler->render()。
 *
 * 行为（模式见 isDebug()）：
 *  - 本地模式：完全交给父类 → 保留原有调试体验（HTML 调试页 / JSON 带真实 msg + traces），即"直接抛出"。
 *  - 线上模式：/api 请求返回 HTTP 500 {"code":500,"msg":"服务内部错误（编号 xxxxxxxx）"}；
 *    异常类/消息/SQL/绑定/请求上下文/堆栈写日志，用编号 grep 即可定位。
 *  - 非 /api 请求：任何模式都走父类默认行为（错误页/调试页），不受影响。
 *
 * 生效方式：
 *  - 插件自带 `plugin/curd/config/exception.php` → 插件路由（plugin\curd\app\controller\*）自动生效；
 *  - 宿主自己的 /api 控制器：宿主 config/exception.php 的 '' 键指向本类（见 INSTALL / README）；
 *    （不能只写 '@'：框架的判定是「本 app 配置里已有 '' 键 ⇒ 忽略 '@'」，宿主骨架默认就有 '' 键。）
 *
 * 模式判定：唯一开关是 .env 的 APP_DEBUG（plugin.curd.app.debug）；见 isDebug()。
 */
class CurdExceptionHandler extends BaseHandler
{
    /**
     * 写日志时的堆栈最大长度（字符），避免超长堆栈把日志撑爆
     */
    protected const MAX_TRACE_LENGTH = 4000;

    /**
     * 本次异常的前后端关联编号（report 时生成，render 复用）
     */
    protected ?string $traceCode = null;

    /**
     * 记录异常详情（覆盖父类的默认日志，改为结构化 + 带编号）
     *
     * 与 PermissionCheck 的 403 编号同一套排查方式：编号回显给用户、同时进日志。
     */
    public function report(Throwable $exception): void
    {
        // 保持父类 dontReport 语义（如 BusinessException 不写日志）
        if ($this->shouldntReport($exception)) {
            return;
        }

        $traceCode = $this->traceCode();

        try {
            $request = \request();
            // 字段顺序有讲究：日志按数组顺序序列化，而 webman 的 LineFormatter 开了
            // allowInlineLineBreaks（config/log.php 第三参 true），trace_str 里的换行会原样落盘。
            // 所以 trace_str 必须放最后，sql/bindings 等关键排查字段放在它之前 ——
            // 否则 `grep <编号>` 只会命中第一行，看不到 SQL 上下文。
            $context = array_merge([
                'trace'     => $traceCode,
                'exception' => get_class($exception),
                'message'   => $this->singleLine($exception->getMessage()),
                'file'      => $exception->getFile() . ':' . $exception->getLine(),
                'method'    => $request ? strtoupper((string)$request->method()) : '',
                'path'      => $request ? (string)$request->path() : '',
                'ip'        => $request && method_exists($request, 'getRealIp') ? $request->getRealIp() : '',
                'user_id'   => $request->user->id ?? null,
                'username'  => $request->user->username ?? null,
            ], $this->sqlContext($exception));

            $context['trace_str'] = $this->singleLine(
                substr($exception->getTraceAsString(), 0, self::MAX_TRACE_LENGTH)
            );

            $this->logger->error('[curd] 接口异常', $context);
        } catch (Throwable $logError) {
            // 日志不可用绝不能影响响应
        }
    }

    public function render(Request $request, Throwable $exception): Response
    {
        // 异常自带 render() 时优先（框架语义，勿绕过）
        if (method_exists($exception, 'render') && ($response = $exception->render($request))) {
            return $response;
        }

        // 非 /api：与父类完全一致（HTML 错误页 / 调试页）
        if (!str_starts_with((string)$request->path(), '/api')) {
            return parent::render($request, $exception);
        }

        // 本地模式：交给父类，保留真实异常消息与 traces（JSON 请求）/调试页（其余）
        if ($this->isDebug()) {
            return parent::render($request, $exception);
        }

        // 线上模式：统一 500 + 可读文案 + 编号
        return json([
            'code' => 500,
            'msg'  => $this->errorMessage() . '（编号 ' . $this->traceCode() . '）',
        ])->withStatus(500);
    }

    /**
     * 本地/线上模式（唯一的模式开关）
     *
     * 取「当前 app 的 debug」与「插件 debug(.env APP_DEBUG)」的交集：
     * 任一为 false 都按线上处理 —— 宁可兜住也不要把堆栈/SQL 泄漏给前端。
     *  - 插件路由：两者是同一个值（plugin.curd.app.debug），等价于只读 APP_DEBUG；
     *  - 宿主路由：前者是宿主 config/app.php 的 debug。
     */
    protected function isDebug(): bool
    {
        return (bool)$this->debug && (bool)config('plugin.curd.app.debug');
    }

    /**
     * 线上模式下的提示文案（不含编号，编号由本类追加）
     */
    protected function errorMessage(): string
    {
        $msg = config('plugin.curd.curd.error_message', '服务内部错误');

        return is_string($msg) && trim($msg) !== '' ? trim($msg) : '服务内部错误';
    }

    /**
     * 本次异常的关联编号（每次异常生成一次，report/render 共用）
     */
    protected function traceCode(): string
    {
        return $this->traceCode ??= substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * 把多行文本收敛成单行（换行 → " | "）
     *
     * 原因：webman 默认日志格式器开了 allowInlineLineBreaks=true（config/log.php），
     * 数组上下文里的换行会原样写进日志文件，导致一条记录横跨几十行：
     *   - `grep <编号>` 只能命中首行，后面的 sql/bindings/堆栈都看不到；
     *   - 日志采集器（filebeat 等）按行解析时会把一条记录拆成多条。
     * 收敛为单行后，一条异常 = 一行日志，可直接 grep / jq / 采集。
     */
    protected function singleLine(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n"], ' | ', $text);
    }

    /**
     * 提取 SQL 上下文（仅数据库异常有，便于线上排错）
     *
     * 用「方法存在性」判断而非 instanceof，避免对 illuminate/database 产生硬耦合
     * （宿主可换 ORM/连接实现，只要异常提供了 getSql/getBindings 就能取到）。
     */
    protected function sqlContext(Throwable $e): array
    {
        if (!method_exists($e, 'getSql') || !method_exists($e, 'getBindings')) {
            return [];
        }

        try {
            $bindings = $e->getBindings();

            return [
                'sql'      => $this->singleLine((string)$e->getSql()),
                'bindings' => is_array($bindings) ? $bindings : [],
            ];
        } catch (Throwable $ignore) {
            return [];
        }
    }
}
