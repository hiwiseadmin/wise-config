<?php
declare(strict_types=1);

namespace wise\config\middleware;

use think\Request;
use think\Response;

/**
 * 配置管理权限中间件
 *
 * 通过配置文件中的 auth_callback 闭包来判断当前用户是否有管理权限。
 * 可在路由中通过 middleware 注册，也可全局使用。
 *
 * 安全策略（默认拒绝）：
 *   - auth_callback 未配置（null）→ 拒绝访问，避免“默认放行”造成越权；
 *   - auth_callback 已配置但不可调用 → 拒绝访问；
 *   - 回调必须显式返回 true 才放行。
 *
 * 若确需匿名放行，请在 config/wise-config.php 中显式配置
 * 'auth_allow_when_unconfigured' => true，并自行评估风险。
 */
class ConfigAuth
{
    /**
     * 处理请求
     *
     * @param Request  $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $callback = config('wise-config.auth_callback');

        // 未配置回调：默认拒绝；仅当显式开启放行开关时才通过
        if ($callback === null) {
            if (config('wise-config.auth_allow_when_unconfigured', false) === true) {
                return $next($request);
            }

            return $this->deny($request, '权限未配置，已拒绝访问', 403);
        }

        // 已配置但不可调用，拒绝访问以防安全风险
        if (!is_callable($callback)) {
            return $this->deny($request, '权限配置错误', 500);
        }

        $allowed = $callback($request);
        if ($allowed !== true) {
            return $this->deny($request, '无权限访问', 403);
        }

        return $next($request);
    }

    /**
     * 构造拒绝响应
     *
     * 直接构造 Json 响应，不依赖 JsonResponse trait：
     * 该 trait 的 error() 在 app.debug + IP 白名单命中时会返回 string，
     * 对其链式调用 ->code() 会触发 “Call to a member function code() on string”。
     *
     * @param Request $request
     * @param string  $message    面向用户的提示文案
     * @param int     $statusCode HTTP 状态码（同时作为响应体 code）
     * @return Response
     */
    protected function deny(Request $request, string $message, int $statusCode): Response
    {
        if ($request->isAjax() || $request->isJson()) {
            return json([
                'code'      => $statusCode,
                'msg'       => $message,
                'data'      => [],
                'timestamp' => time(),
            ], $statusCode);
        }

        return Response::create($message, 'html', $statusCode);
    }
}
