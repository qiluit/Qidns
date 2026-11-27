<?php

declare(strict_types=1);

namespace QiDNS\Lib\Provider;

/**
 * DNS 服务商统一抽象接口
 * 参考 HiDNS / dnsmgr 的 Provider 设计，但精简到 QiDNS 实际需要的三平台
 */
interface ProviderInterface
{
    /** 注册状态（各服务商统一口径） */
    public const STATUS_UNKNOWN = 0;   // 未知/查不到
    public const STATUS_RENEW   = 1;   // 急需续费（已过期，宽限期内仍可续）
    public const STATUS_REDEEM  = 2;   // 赎回期（已过期，仍可赎回）
    public const STATUS_NORMAL  = 3;   // 正常
    public const STATUS_DEAD    = 4;   // 不可赎回（已过赎回期，域名被释放或被注册商持有）

    /** 服务商标识 */
    public function code(): string;

    /** 校验凭证是否有效，返回 bool */
    public function test(): bool;

    /** 拉取账号下所有域名，返回 [['domain'=>'example.com','expire_at'=>'2027-01-01'], ...] */
    public function listDomains(): array;

    /**
     * 拉取「注册商侧真实持有」的域名（DNS 管理 / 真实归属视图的数据源）。
     * 仅注册商类服务商实现；纯解析托管商返回空数组。
     * 返回 [['domain'=>'example.com','expire_at'=>'2027-01-01','domain_status'=>3], ...]
     * domain_status: 0未知 1急需续费 2赎回期(仍可赎回) 3正常 4不可赎回
     */
    public function listOwnedDomains(): array;

    /**
     * 拉取某域名的全部解析记录
     * @return array 统一结构：[['rr'=>'@','type'=>'A','value'=>'1.2.3.4','ttl'=>600,'line'=>'默认','mx'=>0,'status'=>1,'remote_id'=>'...'], ...]
     */
    public function listRecords(string $domain): array;

    /**
     * 新增记录
     * @return string 服务商侧记录 ID
     */
    public function createRecord(string $domain, array $record): string;

    /** 更新记录 */
    public function updateRecord(string $domain, string $remoteId, array $record): bool;

    /** 删除记录 */
    public function deleteRecord(string $domain, string $remoteId): bool;

    /**
     * 修改域名在注册商处的 NS（DNS 服务器）指向。
     * 仅注册商类服务商支持；纯解析托管商返回 false/抛异常。
     * @param string $domain 域名
     * @param array  $ns     新的 NS 主机名数组（不含结尾点，1~6 个）
     * @return bool 是否提交成功
     */
    public function setNameservers(string $domain, array $ns): bool;

    /**
     * 读取域名在注册商处登记的当前 NS。
     * @return string[] NS 主机名数组；不支持或查不到返回空数组
     */
    public function getNameservers(string $domain): array;
}
