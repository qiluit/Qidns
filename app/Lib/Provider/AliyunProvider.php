<?php

declare(strict_types=1);

namespace QiDNS\Lib\Provider;

/**
 * 阿里云云解析 Aliyun DNS 适配器（RPC 签名，HMAC-SHA1）
 * 凭证：access_key_id, access_key_secret
 */
class AliyunProvider extends BaseProvider implements ProviderInterface
{
    private const ENDPOINT = 'https://alidns.aliyuncs.com';          // 云解析 DNS：解析托管维度
    private const VERSION = '2015-01-09';
    private const DOMAIN_ENDPOINT = 'https://domain.aliyuncs.com';    // 域名：注册商真实持有维度
    private const DOMAIN_VERSION = '2018-01-29';

    /** 注册域名 NS 缓存（listOwnedDomains 拉取时顺带收集，避免逐域名翻页查询） */
    private ?array $ownedNsCache = null;

    public function code(): string
    {
        return 'aliyun';
    }

    public function test(): bool
    {
        try {
            $this->call('DescribeDomains', ['PageSize' => 1]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * DNS 托管维度：DescribeDomains 返回「账号里建过的解析 zone」，
     * 域名过期/转手/NS 迁走后 zone 仍保留，所以天然含历史残留。
     *
     * 排除规则（实测依据）：zone 自带 AliDomain 字段标识「是否本账号在阿里云注册持有」。
     * 本账号 60 个 zone 中 AliDomain=true 恰为 25 个，与 QueryDomainList 真实持有列表
     * 完全一致；其余 35 个 false 的 zone 注册局实际 NS 无一指向阿里云
     * （21 个已迁 DNSPod / 19 个其他 NS / 16 个查无 NS），属于不属于该账号的残留。
     * 因此只采纳 AliDomain=true 的 zone，残留 zone 在数据源头即被排除。
     *
     * 注意：该接口不返回 ExpireDate，域名到期日由 listOwnedDomains() 提供。
     */
    public function listDomains(): array
    {
        $result = [];
        $page = 1;
        $scanned = 0;
        do {
            $resp = $this->call('DescribeDomains', ['PageNumber' => $page, 'PageSize' => 100]);
            $list = $resp['Domains']['Domain'] ?? [];
            // 单条时阿里云返回对象而非数组，统一处理
            if (isset($list['DomainName'])) {
                $list = [$list];
            }
            foreach ($list as $item) {
                $scanned++;
                $domain = trim((string)($item['DomainName'] ?? ''));
                if ($domain === '') {
                    continue;
                }
                // 残留 zone 不入库： AliDomain=false 即非本账号持有，实测 NS 已全部迁走
                if (!filter_var($item['AliDomain'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                $result[] = [
                    'domain' => $domain,
                    'expire_at' => '', // DescribeDomains 无此字段，到期日来自 QueryDomainList
                ];
            }
            $total = (int)($resp['TotalCount'] ?? 0);
            $page++;
            if ($scanned >= $total) {
                break;
            }
        } while (!empty($list));

        return $result;
    }

    /**
     * 注册商维度：QueryDomainList 返回账号「真实持有」的域名（含到期日与状态）。
     * 这是「DNS 管理」视图的数据源，也是甄别 DescribeDomains 残留 zone 的白名单。
     * domain_status: 1 急需续费 / 2 急需赎回 / 3 正常
     */
    public function listOwnedDomains(): array
    {
        $result = [];
        $nsCache = [];
        $page = 1;
        do {
            $resp = $this->callDomain('QueryDomainList', ['PageNum' => $page, 'PageSize' => 100]);
            $list = $resp['Data']['Domain'] ?? [];
            if (isset($list['DomainName'])) {
                $list = [$list];
            }
            foreach ($list as $item) {
                $domain = trim((string)($item['DomainName'] ?? ''));
                if ($domain === '') {
                    continue;
                }
                $expire = $this->parseDate((string)($item['ExpirationDate'] ?? ''));
                // 阿里云只定义 1 续费期 / 2 赎回期 / 3 正常，其余取值（如未实名 0）用到期日兜底，
                // 这样「过了赎回期」也能落到 4 不可赎回，与其它注册商口径一致
                $status = (int)($item['DomainStatus'] ?? 0);
                if (!in_array($status, [ProviderInterface::STATUS_RENEW, ProviderInterface::STATUS_REDEEM, ProviderInterface::STATUS_NORMAL], true)) {
                    $status = $this->statusFromExpire($expire);
                }
                $result[] = [
                    'domain' => $domain,
                    'expire_at' => $expire,
                    'domain_status' => $status,
                ];
                // 顺带收集注册商登记的 NS（GetDomainDetail 在该服务版本不存在，只能取列表自带字段）
                $nsList = $item['DnsList']['DnsList'] ?? [];
                if (is_string($nsList)) {
                    $nsList = [$nsList];
                }
                $servers = [];
                foreach ((array)$nsList as $s) {
                    $s = trim((string)$s);
                    if ($s !== '') {
                        $servers[] = $s;
                    }
                }
                if ($servers) {
                    $nsCache[strtolower($domain)] = $servers;
                }
            }
            $totalPage = (int)($resp['TotalPageNum'] ?? 1);
            $page++;
        } while ($page <= $totalPage && !empty($list));

        $this->ownedNsCache = $nsCache;
        return $result;
    }

    public function listRecords(string $domain): array
    {
        $result = [];
        $page = 1;
        do {
            $resp = $this->call('DescribeDomainRecords', [
                'DomainName' => $domain,
                'PageNumber' => $page,
                'PageSize' => 100,
            ]);
            $list = $resp['DomainRecords']['Record'] ?? [];
            if (isset($list['RecordId'])) {
                $list = [$list];
            }
            foreach ($list as $item) {
                $result[] = $this->normalize($item);
            }
            $total = (int)($resp['TotalCount'] ?? 0);
            $page++;
            if (count($result) >= $total) {
                break;
            }
        } while (!empty($list));

        return $result;
    }

    public function createRecord(string $domain, array $record): string
    {
        $resp = $this->call('AddDomainRecord', $this->buildPayload($domain, $record));
        $id = (string)($resp['RecordId'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('阿里云未返回 RecordId');
        }
        return $id;
    }

    public function updateRecord(string $domain, string $remoteId, array $record): bool
    {
        $payload = $this->buildPayload($domain, $record);
        $payload['RecordId'] = $remoteId;
        $this->call('UpdateDomainRecord', $payload);
        return true;
    }

    public function deleteRecord(string $domain, string $remoteId): bool
    {
        $this->call('DeleteDomainRecord', ['RecordId' => $remoteId]);
        return true;
    }

    /**
     * 修改注册商登记的 NS（域名接口 domain.aliyuncs.com / SaveBatchTaskForModifyingDomainDns）。
     *
     * 该能力是「异步批量任务」：提交只返回 TaskNo，真正执行结果要用 QueryTaskDetailList 查。
     * 因此这里必须轮询任务详情，否则会出现「提交成功但实际执行失败」却提示成功的假成功。
     * 注意：不存在 UpdateDomainNameServer 这个 Action（旧实现调用即 InvalidAction.NotFound）。
     *
     * 数组参数按 RPC 规范展开为 DomainName.1 / DomainNameServer.1…
     */
    public function setNameservers(string $domain, array $ns): bool
    {
        $servers = [];
        foreach (array_values($ns) as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $servers[] = $s;
            }
        }
        if (count($servers) < 2) {
            throw new \RuntimeException('阿里云要求至少填写 2 个 NS');
        }

        $params = [
            'UserClientIp' => '127.0.0.1',
            'AliyunDns' => 'false',        // false = 使用下面显式指定的 NS
            'DomainName.1' => $domain,
        ];
        foreach ($servers as $i => $server) {
            $params['DomainNameServer.' . ($i + 1)] = $server;
        }

        $resp = $this->callDomain('SaveBatchTaskForModifyingDomainDns', $params);
        $taskNo = trim((string)($resp['TaskNo'] ?? ''));
        if ($taskNo === '') {
            throw new \RuntimeException('阿里云未返回任务编号，无法确认修改结果');
        }
        $this->waitDomainTask($taskNo, $domain);
        return true;
    }

    /**
     * 轮询域名任务详情直到终态。
     * TaskStatusCode：0 等待执行 / 1 执行中 / 2 成功 / 3 失败。
     * 超时仍非终态时不报错（任务已受理），由界面提示「已提交，稍后生效」。
     */
    private function waitDomainTask(string $taskNo, string $domain, int $tries = 8, int $intervalMs = 1500): void
    {
        for ($i = 0; $i < $tries; $i++) {
            $resp = $this->callDomain('QueryTaskDetailList', [
                'TaskNo' => $taskNo,
                'PageNum' => 1,
                'PageSize' => 20,
            ]);
            $list = $resp['Data']['TaskDetail'] ?? [];
            if (isset($list['DomainName'])) {
                $list = [$list];   // 单条时阿里云返回对象而非数组
            }
            foreach ((array)$list as $item) {
                if (strcasecmp((string)($item['DomainName'] ?? ''), $domain) !== 0) {
                    continue;
                }
                $status = (int)($item['TaskStatusCode'] ?? 0);
                if ($status === 2) {
                    return;
                }
                if ($status === 3) {
                    $msg = trim((string)($item['ErrorMsg'] ?? '')) ?: trim((string)($item['FailReason'] ?? ''));
                    throw new \RuntimeException('阿里云任务执行失败：' . ($msg !== '' ? $msg : '未知原因'));
                }
            }
            usleep($intervalMs * 1000);
        }
    }

    /**
     * 读取注册商登记的当前 NS。
     * 该服务版本(2018-01-29)没有 GetDomainDetail/DescribeDomainInfo，
     * NS 只能从 QueryDomainList 每条自带的 DnsList 取（listOwnedDomains 已顺带收集）。
     */
    public function getNameservers(string $domain): array
    {
        $key = strtolower(trim($domain));
        if ($this->ownedNsCache === null) {
            $this->listOwnedDomains(); // 惰性加载缓存（内部会填充 ownedNsCache）
        }
        return $this->ownedNsCache[$key] ?? [];
    }

    // ---- 内部 ----

    private function buildPayload(string $domain, array $r): array
    {
        $payload = [
            'DomainName' => $domain,
            'RR' => $r['rr'] === '@' ? '@' : $r['rr'],
            'Type' => $r['type'],
            'Value' => $r['value'],
            'TTL' => (int)($r['ttl'] ?? 600),
            'Line' => $this->mapLine($r['line'] ?? '默认'),
        ];
        if (($r['type'] ?? '') === 'MX') {
            $payload['Priority'] = (int)($r['mx'] ?? 0);
        }
        return $payload;
    }

    private function normalize(array $item): array
    {
        return [
            'rr' => $item['RR'] ?? '@',
            'type' => $item['Type'] ?? 'A',
            'value' => $item['Value'] ?? '',
            'ttl' => (int)($item['TTL'] ?? 600),
            'line' => $this->unmapLine($item['Line'] ?? '默认'),
            'mx' => (int)($item['Priority'] ?? 0),
            'status' => (($item['Status'] ?? 'ENABLE') === 'ENABLE') ? 1 : 0,
            'remote_id' => (string)($item['RecordId'] ?? ''),
        ];
    }

    private function mapLine(string $line): string
    {
        $map = [
            '默认' => 'default', '电信' => 'telecom', '联通' => 'unicom', '移动' => 'mobile',
            '教育网' => 'edu', '海外' => 'oversea', '搜索引擎' => 'search',
        ];
        return $map[$line] ?? 'default';
    }

    private function unmapLine(string $line): string
    {
        $map = [
            'default' => '默认', 'telecom' => '电信', 'unicom' => '联通', 'mobile' => '移动',
            'edu' => '教育网', 'oversea' => '海外', 'search' => '搜索引擎',
        ];
        return $map[$line] ?? $line;
    }

    private function parseDate(string $str): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $str, $m)) {
            return $m[0];
        }
        return '';
    }

    /** 云解析 DNS 接口（DescribeDomains / 记录增删改） */
    private function call(string $action, array $params): array
    {
        return $this->rpc(self::ENDPOINT, self::VERSION, $action, $params);
    }

    /** 域名接口（QueryDomainList 等注册商侧能力） */
    private function callDomain(string $action, array $params): array
    {
        return $this->rpc(self::DOMAIN_ENDPOINT, self::DOMAIN_VERSION, $action, $params);
    }

    /** 阿里云 RPC 通用调用（HMAC-SHA1 签名），两套产品共用同一套签名逻辑 */
    private function rpc(string $endpoint, string $version, string $action, array $params): array
    {
        $accessKeyId = $this->credentials['access_key_id'] ?? '';
        $accessKeySecret = $this->credentials['access_key_secret'] ?? '';

        $common = [
            'Action' => $action,
            'Format' => 'JSON',
            'Version' => $version,
            'AccessKeyId' => $accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => md5(uniqid((string)mt_rand(), true)),
        ];
        $all = array_merge($common, $params);
        ksort($all);

        $query = [];
        foreach ($all as $k => $v) {
            $query[] = $this->percentEncode($k) . '=' . $this->percentEncode((string)$v);
        }
        $canonical = implode('&', $query);
        $stringToSign = 'GET&%2F&' . $this->percentEncode($canonical);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
        $query[] = 'Signature=' . $this->percentEncode($signature);

        $url = $endpoint . '/?' . implode('&', $query);
        $resp = $this->httpGet($url);
        $data = json_decode($resp['body'], true);

        if (isset($data['Code'])) {
            throw new \RuntimeException('阿里云: ' . $data['Code'] . ' - ' . ($data['Message'] ?? ''));
        }
        return $data ?? [];
    }

    private function percentEncode(string $str): string
    {
        $encoded = rawurlencode($str);
        return str_replace(['%7E', '+', '*'], ['~', '%20', '%2A'], $encoded);
    }
}
