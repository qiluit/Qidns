<?php

declare(strict_types=1);

namespace QiDNS\Lib\Provider;

/**
 * Provider 工厂
 *
 * 腾讯云 DNSPod 的两套 API（传统 Token / API 3.0）已整合为一个服务商：
 *  - qd_account.provider 统一记为 dnspod，一个账号的凭证同时可含 login_token 与 secret_id/key
 *  - dnspod_tencent 仅作为历史凭证类型的兼容别名，同样构造统一 provider
 *  - qd_domain.dns_provider / registrar 全是 dnspod，归一到同一逻辑服务商，
 *    避免同步任一套 API 时覆盖另一套的域名归属
 */
class ProviderFactory
{
    /** 历史凭证类型 → 逻辑服务商（兼容迁移，新数据统一用 dnspod） */
    private const LOGICAL_MAP = [
        'dnspod_tencent' => 'dnspod',
    ];

    public static function make(string $provider, array $credentials): ProviderInterface
    {
        switch ($provider) {
            case 'dnspod':
            case 'dnspod_tencent':
                return new DnspodUnifiedProvider($credentials);
            case 'aliyun':
                return new AliyunProvider($credentials);
            case 'west':
                return new WestProvider($credentials);
            default:
                throw new \InvalidArgumentException('不支持的服务商: ' . $provider);
        }
    }

    /** 凭证类型归一为逻辑服务商 */
    public static function logicalProvider(string $provider): string
    {
        return self::LOGICAL_MAP[$provider] ?? $provider;
    }
}
