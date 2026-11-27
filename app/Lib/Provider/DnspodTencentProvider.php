<?php

declare(strict_types=1);

namespace QiDNS\Lib\Provider;

/**
 * 腾讯云 API 3.0 适配器（同时覆盖 DNSPod 解析与腾讯云域名注册）
 *
 * 同一个腾讯云账号下有两套相互独立的能力，凭证相同（SecretId/SecretKey + TC3 签名）：
 *  - 解析托管：dnspod.tencentcloudapi.com / 2021-03-23
 *    DescribeDomainList(解析zone) / DescribeRecordList / CreateRecord / ModifyRecord / DeleteRecord
 *  - 域名注册：domain.tencentcloudapi.com / 2018-08-08
 *    DescribeDomainList(真实持有域名) —— 「DNS 管理」视图的数据源
 *
 * 两套接口同名 DescribeDomainList，但返回字段不同：
 *  解析 = Response.DomainList + DomainCountInfo；注册 = Response.DomainSet + TotalCount
 * 因此分别用 listDomains() / listOwnedDomains() 区分，内部 call() 支持指定服务与版本。
 *
 * 注意：TC3 的 canonical_headers 自身以 \n 结尾，拼接时还要再加一个 \n，
 * 即 headers 与 signed_headers 之间存在一个空行，少了会报 AuthFailure.SignatureFailure。
 */
class DnspodTencentProvider extends BaseProvider implements ProviderInterface
{
    private const DNS_HOST = 'dnspod.tencentcloudapi.com';
    private const DNS_SERVICE = 'dnspod';
    private const DNS_VERSION = '2021-03-23';

    private const DOMAIN_HOST = 'domain.tencentcloudapi.com';
    private const DOMAIN_SERVICE = 'domain';
    private const DOMAIN_VERSION = '2018-08-08';

    public function code(): string
    {
        return 'dnspod_tencent';
    }

    public function test(): bool
    {
        try {
            $this->call('DescribeDomainList', ['Offset' => 0, 'Limit' => 1]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 解析托管维度：DescribeDomainList 返回账号里建过的解析 zone。
     * 与注册域名无关，zone 在域名转手后仍可能残留。
     */
    public function listDomains(): array
    {
        $result = [];
        $offset = 0;
        $length = 100;
        do {
            $resp = $this->call('DescribeDomainList', [
                'Type' => 'ALL',
                'Offset' => $offset,
                'Limit' => $length,
            ], self::DNS_HOST, self::DNS_SERVICE, self::DNS_VERSION);
            $list = $resp['DomainList'] ?? [];
            foreach ($list as $item) {
                $result[] = [
                    'domain' => $item['Name'] ?? '',
                    'expire_at' => '', // 解析托管无到期日，由注册商维度补充
                    'domain_id' => (string)($item['DomainId'] ?? ''),
                ];
            }
            $count = $resp['DomainCountInfo'] ?? [];
            $total = (int)($count['DomainTotal'] ?? $count['AllTotal'] ?? 0);
            $offset += count($list);
            if (empty($list) || count($list) < $length || ($total > 0 && $offset >= $total)) {
                break;
            }
        } while (true);

        return $result;
    }

    /**
     * 注册商维度：domain 服务 DescribeDomainList 返回账号真实注册的域名。
     * 这是「DNS 管理」视图的数据源，也是甄别解析 zone 残留的白名单。
     * 返回字段是 DomainSet（不是解析服务的 DomainList），到期日为 ExpirationDate。
     * domain_status: 0未知 1急需续费 2急需赎回 3正常
     */
    public function listOwnedDomains(): array
    {
        $result = [];
        $offset = 0;
        $length = 100;
        do {
            $resp = $this->call('DescribeDomainList', [
                'Offset' => $offset,
                'Limit' => $length,
            ], self::DOMAIN_HOST, self::DOMAIN_SERVICE, self::DOMAIN_VERSION);
            $list = $resp['DomainSet'] ?? [];
            foreach ($list as $item) {
                $domain = trim((string)($item['DomainName'] ?? ''));
                if ($domain === '') {
                    continue;
                }
                $result[] = [
                    'domain' => $domain,
                    'expire_at' => $this->parseDate((string)($item['ExpirationDate'] ?? '')),
                    'domain_status' => $this->mapStatus($item),
                ];
            }
            $total = (int)($resp['TotalCount'] ?? 0);
            $offset += count($list);
            if (empty($list) || count($list) < $length || ($total > 0 && $offset >= $total)) {
                break;
            }
        } while (true);

        return $result;
    }

    /**
     * 修改注册商处域名 NS（domain 服务 / ModifyDomainDNSBatch）。
     * 该接口需要 DomainId，先按域名从 DescribeDomainList 精确查找。
     */
    public function setNameservers(string $domain, array $ns): bool
    {
        $info = $this->findDomainInfo($domain);
        if (!$info) {
            throw new \RuntimeException('腾讯云注册域名列表中找不到 ' . $domain . '，无法改 NS');
        }
        $domainId = (string)($info['DomainId'] ?? '');
        if ($domainId === '') {
            throw new \RuntimeException('腾讯云未返回该域名的 DomainId');
        }
        $servers = [];
        foreach (array_values($ns) as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $servers[] = $s;
            }
        }
        if (!$servers) {
            throw new \RuntimeException('NS 列表为空');
        }
        $this->call('ModifyDomainDNSBatch', [
            'Domains' => [$domain],
            'Dns' => $servers,
        ], self::DOMAIN_HOST, self::DOMAIN_SERVICE, self::DOMAIN_VERSION);
        return true;
    }

    /** 读取注册商登记的当前 NS（DescribeDomainList 中的 Dns 字段） */
    public function getNameservers(string $domain): array
    {
        $info = $this->findDomainInfo($domain);
        if (!$info) {
            return [];
        }
        $out = [];
        foreach ((array)($info['Dns'] ?? []) as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** 按域名精确查找注册域名信息（含 DomainId / Dns / 锁定状态） */
    private function findDomainInfo(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        $offset = 0;
        $length = 100;
        do {
            $resp = $this->call('DescribeDomainList', [
                'Offset' => $offset,
                'Limit' => $length,
            ], self::DOMAIN_HOST, self::DOMAIN_SERVICE, self::DOMAIN_VERSION);
            $list = $resp['DomainSet'] ?? [];
            foreach ($list as $item) {
                if (strtolower(trim((string)($item['DomainName'] ?? ''))) === $domain) {
                    return $item;
                }
            }
            $total = (int)($resp['TotalCount'] ?? 0);
            $offset += count($list);
            if (empty($list) || $offset >= $total) {
                break;
            }
        } while (true);
        return null;
    }

    public function listRecords(string $domain): array
    {
        $result = [];
        $offset = 0;
        $length = 3000;
        do {
            $resp = $this->call('DescribeRecordList', [
                'Domain' => $domain,
                'Offset' => $offset,
                'Limit' => $length,
                'ErrorOnEmpty' => 'no', // 空记录域名返回空列表而非报错
            ], self::DNS_HOST, self::DNS_SERVICE, self::DNS_VERSION);
            $list = $resp['RecordList'] ?? [];
            foreach ($list as $item) {
                $result[] = $this->normalize($item);
            }
            $total = (int)($resp['RecordCountInfo']['TotalCount'] ?? 0);
            $offset += count($list);
            if (empty($list) || count($list) < $length || ($total > 0 && $offset >= $total)) {
                break;
            }
        } while (true);

        return $result;
    }

    public function createRecord(string $domain, array $record): string
    {
        $params = [
            'Domain' => $domain,
            'SubDomain' => $record['rr'] === '@' ? '@' : $record['rr'],
            'RecordType' => $record['type'],
            'RecordLine' => $this->mapLine($record['line'] ?? '默认'),
            'Value' => $record['value'],
        ];
        if (isset($record['ttl'])) {
            $params['TTL'] = (int)$record['ttl'];
        }
        if (($record['type'] ?? '') === 'MX' && isset($record['mx'])) {
            $params['MX'] = (int)$record['mx'];
        }

        $resp = $this->call('CreateRecord', $params, self::DNS_HOST, self::DNS_SERVICE, self::DNS_VERSION);
        $id = (string)($resp['RecordId'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('DNSPod(API 3.0) 未返回 RecordId');
        }
        return $id;
    }

    public function updateRecord(string $domain, string $remoteId, array $record): bool
    {
        $params = [
            'Domain' => $domain,
            'RecordId' => (int)$remoteId,
            'SubDomain' => $record['rr'] === '@' ? '@' : $record['rr'],
            'RecordType' => $record['type'],
            'RecordLine' => $this->mapLine($record['line'] ?? '默认'),
            'Value' => $record['value'],
        ];
        if (isset($record['ttl'])) {
            $params['TTL'] = (int)$record['ttl'];
        }
        if (($record['type'] ?? '') === 'MX' && isset($record['mx'])) {
            $params['MX'] = (int)$record['mx'];
        }

        $this->call('ModifyRecord', $params, self::DNS_HOST, self::DNS_SERVICE, self::DNS_VERSION);
        return true;
    }

    public function deleteRecord(string $domain, string $remoteId): bool
    {
        $this->call('DeleteRecord', [
            'Domain' => $domain,
            'RecordId' => (int)$remoteId,
        ], self::DNS_HOST, self::DNS_SERVICE, self::DNS_VERSION);
        return true;
    }

    // ---- 内部 ----

    private function normalize(array $item): array
    {
        return [
            'rr' => ($item['Name'] ?? '') !== '' ? $item['Name'] : '@',
            'type' => $item['Type'] ?? 'A',
            'value' => $item['Value'] ?? '',
            'ttl' => (int)($item['TTL'] ?? 600),
            'line' => $item['Line'] ?? '默认',
            'mx' => (int)($item['MX'] ?? 0),
            'status' => (($item['Status'] ?? 'ENABLE') === 'ENABLE') ? 1 : 0,
            'remote_id' => (string)($item['RecordId'] ?? ''),
        ];
    }

    private function mapLine(string $line): string
    {
        $map = [
            '默认' => '默认', '电信' => '电信', '联通' => '联通', '移动' => '移动',
            '教育网' => '教育网', '海外' => '境外', '境外' => '境外', '搜索引擎' => '搜索引擎',
        ];
        return $map[$line] ?? '默认';
    }

    private function parseDate(string $str): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', trim($str), $m)) {
            return $m[0];
        }
        return '';
    }

    /** 腾讯云域名的 DomainStatus 是字符串数组（如 ["ok"]），BuyStatus 提示临期/赎回 */
    private function mapStatus(array $item): int
    {
        $buy = (string)($item['BuyStatus'] ?? '');
        // 赎回失败/已删除 = 过完赎回期，无法再救回
        if (strpos($buy, 'RedeemFail') !== false || strpos($buy, 'Deleted') !== false) {
            return ProviderInterface::STATUS_DEAD;
        }
        if (strpos($buy, 'Redeem') !== false) {
            return ProviderInterface::STATUS_REDEEM;
        }
        if (strpos($buy, 'Renew') !== false || strpos($buy, 'Expire') !== false) {
            return ProviderInterface::STATUS_RENEW;
        }
        if ($buy === '') {
            // 接口没给状态：按到期日兜底，避免状态永远「未知」
            $expire = $this->parseDate((string)($item['ExpirationDate'] ?? ''));
            return $this->statusFromExpire($expire);
        }
        return ProviderInterface::STATUS_NORMAL;
    }

    /**
     * 调用 API 3.0（POST JSON + TC3-HMAC-SHA256 签名）
     * 通过 host/service/version 参数复用同一套签名逻辑，覆盖解析与注册两类服务。
     */
    private function call(string $action, array $params, string $host, string $service, string $version): array
    {
        $secretId = trim((string)($this->credentials['secret_id'] ?? ''));
        $secretKey = trim((string)($this->credentials['secret_key'] ?? ''));
        if ($secretId === '' || $secretKey === '') {
            throw new \RuntimeException('腾讯云 API 3.0 凭证不完整（需 SecretId + SecretKey）');
        }

        $payload = json_encode($params, JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);

        $canonicalHeaders = "content-type:application/json\nhost:" . $host . "\n";
        $canonicalRequest = "POST\n"
            . "/\n"
            . "\n"
            . $canonicalHeaders . "\n"
            . "content-type;host\n"
            . hash('sha256', $payload);

        $stringToSign = "TC3-HMAC-SHA256\n"
            . $timestamp . "\n"
            . $date . '/' . $service . "/tc3_request\n"
            . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $kService = hash_hmac('sha256', $service, $kDate, true);
        $kSigning = hash_hmac('sha256', 'tc3_request', $kService, true);
        $signature = bin2hex(hash_hmac('sha256', $stringToSign, $kSigning, true));

        $authorization = 'TC3-HMAC-SHA256 '
            . 'Credential=' . $secretId . '/' . $date . '/' . $service . '/tc3_request, '
            . 'SignedHeaders=content-type;host, '
            . 'Signature=' . $signature;

        $headers = [
            'Host: ' . $host,
            'Content-Type: application/json',
            'X-TC-Action: ' . $action,
            'X-TC-Version: ' . $version,
            'X-TC-Timestamp: ' . $timestamp,
            'Authorization: ' . $authorization,
        ];

        $resp = $this->httpPost('https://' . $host . '/', $payload, $headers);
        $data = json_decode($resp['body'], true);
        if ($data === null) {
            throw new \RuntimeException('腾讯云 API 3.0 响应解析失败: ' . substr($resp['body'], 0, 200));
        }

        $error = $data['Response']['Error'] ?? null;
        if ($error) {
            throw new \RuntimeException(
                '腾讯云 API 3.0: ' . ($error['Message'] ?? '未知错误') . ' (code=' . ($error['Code'] ?? '') . ')'
            );
        }
        return $data['Response'];
    }
}
