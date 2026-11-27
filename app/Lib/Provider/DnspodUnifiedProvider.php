<?php

declare(strict_types=1);

namespace QiDNS\Lib\Provider;

/**
 * 腾讯云 DNSPod 统一服务商（一个账号 = 一套凭证 = 一个服务商）
 *
 * 腾讯云同一个账号有两套 API、对应菜单的两种权限需求：
 *  1) 传统 DNSPod Token（dnsapi.cn）—— 只管解析 zone 与记录
 *  2) 腾讯云 API 3.0（TC3 签名）    —— 解析 zone/记录 + 注册域名真实持有（domain 服务）
 *
 * 本类把二者编排成单一服务商：
 *  - 解析类操作（listDomains / 记录 CRUD）：优先 API 3.0，凭证缺失或调用失败时回退传统 Token，
 *    两套都不可用才抛出错误，保证「至少填写一套」即可工作。
 *  - 注册商持有（listOwnedDomains）：只有 API 3.0 具备，供「DNS 管理」视图使用；
 *    未配置 API 3.0 时返回空数组（该域名只能靠 NS 实证的有效解析证据判定归属）。
 *
 * test()：任意一套凭证连通即视为成功，并报告每套的连通情况。
 */
class DnspodUnifiedProvider extends BaseProvider implements ProviderInterface
{
    /** @var array{available:bool, error:string} */
    private $legacy = ['available' => false, 'error' => '未配置'];
    /** @var array{available:bool, error:string} */
    private $api3 = ['available' => false, 'error' => '未配置'];

    public function __construct(array $credentials)
    {
        parent::__construct($credentials);
        $hasLegacy = trim((string)($credentials['login_token'] ?? '')) !== ''
            || (trim((string)($credentials['id'] ?? '')) !== '' && trim((string)($credentials['token'] ?? '')) !== '');
        $hasApi3 = trim((string)($credentials['secret_id'] ?? '')) !== ''
            && trim((string)($credentials['secret_key'] ?? '')) !== '';
        if ($hasLegacy) {
            $this->legacy['available'] = true;
        }
        if ($hasApi3) {
            $this->api3['available'] = true;
        }
    }

    public function code(): string
    {
        return 'dnspod';
    }

    public function test(): bool
    {
        $ok = false;
        if ($this->api3['available']) {
            try {
                $ok = (new DnspodTencentProvider($this->credentials))->test() || $ok;
            } catch (\Throwable $e) {
                $this->api3['error'] = $e->getMessage();
            }
        }
        if ($this->legacy['available']) {
            try {
                $ok = (new DnspodProvider($this->credentials))->test() || $ok;
            } catch (\Throwable $e) {
                $this->legacy['error'] = $e->getMessage();
            }
        }
        return $ok;
    }

    /** 每套凭证的连通详情，供账号页展示 */
    public function credentialStatus(): array
    {
        return ['legacy' => $this->legacy, 'api3' => $this->api3];
    }

    /**
     * 解析 zone：优先 API 3.0，失败/未配置回退传统 Token。
     * 两套都返回时合并去重（同一账号两套接口通常等价，合并只为稳妥）。
     */
    public function listDomains(): array
    {
        $result = [];
        $seen = [];
        if ($this->api3['available']) {
            try {
                foreach ((new DnspodTencentProvider($this->credentials))->listDomains() as $d) {
                    $name = strtolower(trim((string)($d['domain'] ?? '')));
                    if ($name !== '' && !isset($seen[$name])) {
                        $seen[$name] = true;
                        $result[] = $d;
                    }
                }
                return $result;
            } catch (\Throwable $e) {
                $this->api3['error'] = $e->getMessage();
            }
        }
        if ($this->legacy['available']) {
            foreach ((new DnspodProvider($this->credentials))->listDomains() as $d) {
                $name = strtolower(trim((string)($d['domain'] ?? '')));
                if ($name !== '' && !isset($seen[$name])) {
                    $seen[$name] = true;
                    $result[] = $d;
                }
            }
            return $result;
        }
        throw new \RuntimeException('DNSPod 未配置任何可用凭证（需传统 Token 或腾讯云 API 3.0）');
    }

    /**
     * 注册商真实持有：仅 API 3.0 的 domain 服务可提供。
     * 未配置 API 3.0 时返回空，不影响「解析管理」视图（走 listDomains）。
     */
    public function listOwnedDomains(): array
    {
        if (!$this->api3['available']) {
            return [];
        }
        try {
            return (new DnspodTencentProvider($this->credentials))->listOwnedDomains();
        } catch (\Throwable $e) {
            $this->api3['error'] = $e->getMessage();
            throw $e;
        }
    }

    /**
     * 改 NS 是注册商操作，只有 API 3.0 的 domain 服务支持，不回退传统 Token。
     */
    public function setNameservers(string $domain, array $ns): bool
    {
        if (!$this->api3['available']) {
            throw new \RuntimeException('修改 NS 需要腾讯云 API 3.0 凭证（当前账号未配置）');
        }
        return (new DnspodTencentProvider($this->credentials))->setNameservers($domain, $ns);
    }

    public function getNameservers(string $domain): array
    {
        if (!$this->api3['available']) {
            return [];
        }
        try {
            return (new DnspodTencentProvider($this->credentials))->getNameservers($domain);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function listRecords(string $domain): array
    {
        return $this->throughParsing(
            fn(ProviderInterface $p) => $p->listRecords($domain)
        );
    }

    public function createRecord(string $domain, array $record): string
    {
        return $this->throughParsing(
            fn(ProviderInterface $p) => $p->createRecord($domain, $record)
        );
    }

    public function updateRecord(string $domain, string $remoteId, array $record): bool
    {
        return $this->throughParsing(
            fn(ProviderInterface $p) => $p->updateRecord($domain, $remoteId, $record)
        );
    }

    public function deleteRecord(string $domain, string $remoteId): bool
    {
        return $this->throughParsing(
            fn(ProviderInterface $p) => $p->deleteRecord($domain, $remoteId)
        );
    }

    /**
     * 解析类写操作的统一路由：优先 API 3.0，失败回退传统 Token。
     * 注意写操作回退可能产生「A 成功一半再 B」的风险，故只在 API 3.0 明确抛错时才回退。
     */
    private function throughParsing(callable $op)
    {
        if ($this->api3['available']) {
            try {
                return $op(new DnspodTencentProvider($this->credentials));
            } catch (\Throwable $e) {
                $this->api3['error'] = $e->getMessage();
            }
        }
        if ($this->legacy['available']) {
            return $op(new DnspodProvider($this->credentials));
        }
        throw new \RuntimeException('DNSPod 未配置任何可用凭证，无法操作解析记录');
    }
}
