<?php

declare(strict_types=1);

namespace QiDNS\Lib\Provider;

/**
 * 抽象基类：统一 HTTP 客户端与错误处理
 */
abstract class BaseProvider
{
    protected $credentials = [];

    /** 过期后仍可正常续费的宽限天数 */
    protected const GRACE_DAYS = 30;

    /** 宽限期之后的赎回期天数（过完即不可赎回） */
    protected const REDEEM_DAYS = 30;

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * 按到期日推算注册状态（供没有状态字段的服务商使用，各服务商口径统一）：
     *   未过期 → 正常；过期 ≤30 天 → 急需续费；过期 30~60 天 → 赎回期；>60 天 → 不可赎回。
     */
    protected function statusFromExpire(string $expire): int
    {
        if ($expire === '') {
            return ProviderInterface::STATUS_UNKNOWN;
        }
        $days = (int)floor((strtotime($expire . ' 23:59:59') - time()) / 86400);
        if ($days >= 0) {
            return ProviderInterface::STATUS_NORMAL;
        }
        if ($days >= -self::GRACE_DAYS) {
            return ProviderInterface::STATUS_RENEW;
        }
        if ($days >= -(self::GRACE_DAYS + self::REDEEM_DAYS)) {
            return ProviderInterface::STATUS_REDEEM;
        }
        return ProviderInterface::STATUS_DEAD;
    }

    /**
     * 默认不具备「注册商真实持有域名」能力，由注册商类适配器覆盖。
     * 这样新增纯解析托管商时无需重复实现空方法。
     */
    public function listOwnedDomains(): array
    {
        return [];
    }

    /** 默认不支持改 NS，由注册商类适配器覆盖 */
    public function setNameservers(string $domain, array $ns): bool
    {
        throw new \RuntimeException('该服务商不支持修改域名 NS');
    }

    /** 默认不支持读取注册商 NS，由注册商类适配器覆盖 */
    public function getNameservers(string $domain): array
    {
        return [];
    }

    /** GET 请求（返回解码后的数组） */
    protected function httpGet(string $url, array $headers = []): array
    {
        return $this->httpRequest('GET', $url, null, $headers);
    }

    /** POST 请求（body 为字符串） */
    protected function httpPost(string $url, string $body = '', array $headers = []): array
    {
        return $this->httpRequest('POST', $url, $body, $headers);
    }

    private function httpRequest(string $method, string $url, ?string $body, array $headers = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        if (is_string($GLOBALS['qidns_config']['curl_cainfo'] ?? null)
            && $GLOBALS['qidns_config']['curl_cainfo'] !== ''
            && is_file($GLOBALS['qidns_config']['curl_cainfo'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CAINFO, $GLOBALS['qidns_config']['curl_cainfo']);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('网络请求失败: ' . $err);
        }

        return ['http_code' => $httpCode, 'body' => $resp];
    }
}
