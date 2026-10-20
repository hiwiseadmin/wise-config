<?php

declare(strict_types=1);

namespace wise\config\resolver;

/**
 * 命名空间三级推断器
 *
 * 按优先级推断当前请求的 namespace：
 *   L1: 显式指定（通过 setExplicitNamespace() 设置）
 *   L2: 请求上下文推断（多应用名 / HTTP Header）
 *   L3: 配置默认值（config('wise-config.namespace.default', 'default')）
 *
 * 支持请求级缓存：同一请求内只推断一次，避免重复计算。
 */
class NamespaceResolver
{
    /** @var string|null 显式指定的 namespace */
    protected ?string $explicitNamespace = null;

    /** @var string|null 缓存的推断结果（请求级） */
    protected ?string $cachedNamespace = null;

    /** @var bool 是否已缓存 */
    protected bool $cached = false;

    /**
     * 设置显式 namespace（链式调用）
     *
     * 设置后会清除请求级缓存，下次调用 resolve() 时优先使用此值。
     *
     * @param string|null $ns 显式 namespace，传 null 表示清除显式指定
     * @return $this
     */
    public function setExplicitNamespace(?string $ns): self
    {
        $this->explicitNamespace = $ns;
        $this->cached = false;
        return $this;
    }

    /**
     * 推断当前请求的 namespace
     *
     * 优先级：显式指定 > 请求上下文 > 配置默认值
     *
     * 同一请求内仅推断一次，结果缓存于 $this->cachedNamespace。
     *
     * @return string 最终生效的 namespace
     */
    public function resolve(): string
    {
        // 请求级缓存：同一个请求内只推断一次
        if ($this->cached) {
            return $this->cachedNamespace;
        }

        // L1: 显式指定
        $namespace = $this->explicitNamespace;

        // L2: 请求上下文推断
        if ($namespace === null) {
            $namespace = $this->fromRequestContext();
        }

        // L3: 配置默认值
        if ($namespace === null) {
            $namespace = (string) config('wise-config.namespace.default', 'default');
        }

        // 统一校验：非法命名空间一律回退到安全默认值，防止路径/缓存键污染
        if (!$this->isValidNamespace($namespace)) {
            $namespace = (string) config('wise-config.namespace.default', 'default');
        }
        if (!$this->isValidNamespace($namespace)) {
            $namespace = 'default';
        }

        $this->cachedNamespace = $namespace;
        $this->cached = true;
        return $this->cachedNamespace;
    }

    /**
     * 校验命名空间合法性
     *
     * 仅允许字母、数字、下划线、中划线、点，长度 1~100。
     * 禁止冒号，避免污染 `namespace:group:key` 路径解析与缓存键。
     *
     * @param mixed $ns
     * @return bool
     */
    public function isValidNamespace($ns): bool
    {
        return is_string($ns)
            && $ns !== ''
            && preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $ns) === 1;
    }

    /**
     * 白名单校验：配置 namespace.allowed 非空时，仅放行其中的命名空间
     *
     * @param string $ns
     * @return bool
     */
    protected function isAllowedNamespace(string $ns): bool
    {
        $allowed = config('wise-config.namespace.allowed', []);
        if (!is_array($allowed) || $allowed === []) {
            return true;
        }

        return in_array($ns, $allowed, true);
    }

    /**
     * 获取最终 namespace（公开方法，等同于 resolve()）
     *
     * @return string 最终生效的 namespace
     */
    public function getNamespace(): string
    {
        return $this->resolve();
    }

    /**
     * 从请求上下文推断 namespace
     *
     * 推断策略（按顺序尝试）：
     *   1. 多应用模式 → 当前 app name（通过 app('http')->getName()）
     *   2. HTTP Header → X-WiseConfig-Namespace
     *
     * 非 HTTP 上下文（如 CLI）静默失败，由 L3 兜底。
     *
     * @return string|null 推断出的 namespace，无法推断时返回 null
     */
    protected function fromRequestContext(): ?string
    {
        // 需要先检查是否启用了自动推断
        if (!config('wise-config.namespace.auto_resolve', true)) {
            return null;
        }

        try {
            // 1. 多应用模式 → 当前 app name（需通过格式与白名单校验）
            if (function_exists('app') && method_exists(app('http'), 'getName')) {
                $appName = (string) app('http')->getName();
                if ($this->isValidNamespace($appName) && $this->isAllowedNamespace($appName)) {
                    return $appName;
                }
            }

            // 2. HTTP Header: X-WiseConfig-Namespace
            //    默认关闭（namespace.allow_request_header=false），开启后仍需通过
            //    格式校验 + 白名单，避免客户端任意切换读取上下文。
            if (config('wise-config.namespace.allow_request_header', false)) {
                $headerNs = (string) request()->header('X-WiseConfig-Namespace', '');
                if ($this->isValidNamespace($headerNs) && $this->isAllowedNamespace($headerNs)) {
                    return $headerNs;
                }
            }
        } catch (\Throwable) {
            // 非 HTTP 上下文（如命令行），静默失败，由 L3 兜底
            return null;
        }

        return null;
    }
}
