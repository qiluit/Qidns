<?php

declare(strict_types=1);

namespace QiDNS\Lib\Provider;

/**
 * 西部数码 West.cn 适配器（代理商业务 API V2.0）
 *
 * 认证：账号级（username + api_password + 毫秒时间戳 → md5 token）
 *   token = md5( username . api_password . 毫秒时间戳 )
 *   公共参数：username / time(毫秒) / token，每次请求动态生成，有效期 10 分钟
 *
 * 服务地址：https://api.west.cn/api/v2
 * 注意：请求与响应均为 GBK/GB2312 编码，需转 UTF-8
 *
 * 能力：一个账号即可拉取总账户下所有域名（getdomains），无需逐域名接入。
 * 前提：需为西部数码正式代理（代理商管理 → API接口配置 设置 API 密码）。
 */
class WestProvider extends BaseProvider implements ProviderInterface
{
    private const ENDPOINT = 'https://api.west.cn/api/v2';

    public function code(): string
    {
        return 'west';
    }

    public function test(): bool
    {
        try {
            $this->request('getdomains', [], 'GET');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 拉取总账户下所有域名（含到期时间与注册状态）
     *
     * 注册状态按**西部数码接口返回的到期日**判定，不回注册局查：
     * 西数对过期域名会先行接盘并自行续费一年（注册局那边显示未过期），
     * 那是平台自己的持有动作，不代表客户仍在持有。以接口时限为准才反映真实归属。
     */
    public function listDomains(): array
    {
        $rows = [];
        foreach ($this->fetchAllItems() as $item) {
            $domain = trim((string)($item['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $expire = $this->parseDate($item['expdate'] ?? $item['expiration'] ?? '');
            $rows[] = [
                'domain' => $domain,
                'expire_at' => $expire,
                'domain_status' => $this->statusFromExpire($expire),
            ];
        }
        return $rows;
    }

    /** 逐页拉取 getdomains，返回原始 item 列表 */
    private function fetchAllItems(): array
    {
        $items = [];
        $page = 1;
        do {
            $resp = $this->request('getdomains', ['limit' => 100, 'page' => $page], 'GET');
            $data = $resp['data'] ?? [];
            $pageItems = $data['items'] ?? [];
            if (isset($pageItems['domain'])) {
                $pageItems = [$pageItems];
            }
            foreach ((array)$pageItems as $item) {
                $items[] = $item;
            }
            $totalpages = (int)($data['totalpages'] ?? $data['totalpage'] ?? 1);
            $page++;
            if ($page > $totalpages) {
                break;
            }
        } while (!empty($pageItems));
        return $items;
    }

    public function listRecords(string $domain): array
    {
        $result = [];
        $page = 1;
        do {
            $resp = $this->request('getdnsrecord', [
                'domain' => $domain,
                'limit' => 100,
                'pageno' => $page,
            ], 'POST');
            $data = $resp['data'] ?? [];
            $items = $data['items'] ?? [];
            if (isset($items['id'])) {
                $items = [$items];
            }
            foreach ((array)$items as $item) {
                $result[] = $this->normalize($item);
            }
            $totalpages = (int)($data['totalpages'] ?? 1);
            $page++;
            if ($page > $totalpages) {
                break;
            }
        } while (!empty($items));

        return $result;
    }

    public function createRecord(string $domain, array $record): string
    {
        $resp = $this->request('adddnsrecord', [
            'domain' => $domain,
            'host' => $record['rr'] === '@' ? '@' : $record['rr'],
            'type' => $record['type'],
            'value' => $record['value'],
            'ttl' => (int)($record['ttl'] ?? 900),
            'level' => (int)($record['mx'] ?? 10),
            'line' => $this->mapLine($record['line'] ?? '默认'),
        ], 'POST');
        $id = (string)($resp['data']['id'] ?? $resp['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('西部数码未返回记录 id');
        }
        return $id;
    }

    public function updateRecord(string $domain, string $remoteId, array $record): bool
    {
        $this->request('moddnsrecord', [
            'domain' => $domain,
            'id' => (int)$remoteId,
            'value' => $record['value'],
            'ttl' => (int)($record['ttl'] ?? 900),
        ], 'POST');
        return true;
    }

    public function deleteRecord(string $domain, string $remoteId): bool
    {
        $this->request('deldnsrecord', [
            'domain' => $domain,
            'id' => (int)$remoteId,
        ], 'POST');
        return true;
    }

    /**
     * 修改域名注册商处 NS（act=moddns，走现有 v2 通道）。
     * 文档：dns1 主 dns、dns2 辅 dns 必填，dns3~dns6 可选，填 NS 主机名（非 IP）。
     */
    public function setNameservers(string $domain, array $ns): bool
    {
        $servers = array_values(array_filter($ns, static fn($s) => trim((string)$s) !== ''));
        if (count($servers) < 2) {
            throw new \RuntimeException('西部数码要求至少 2 个 NS');
        }
        $params = ['domain' => $domain];
        foreach ($servers as $i => $server) {
            if ($i >= 6) {
                break;
            }
            $params['dns' . ($i + 1)] = trim((string)$server);
        }
        $this->request('moddns', $params, 'POST');
        return true;
    }

    /** 读取域名当前 NS（getdomains 返回的 dns1~dns6） */
    public function getNameservers(string $domain): array
    {
        try {
            $resp = $this->request('getdomains', ['domain' => $domain, 'limit' => 1], 'GET');
        } catch (\Throwable $e) {
            return [];
        }
        $data = $resp['data'] ?? [];
        $items = $data['items'] ?? [];
        if (isset($items['domain'])) {
            $items = [$items];
        }
        $out = [];
        foreach ((array)$items as $item) {
            for ($i = 1; $i <= 6; $i++) {
                $s = trim((string)($item['dns' . $i] ?? ''));
                if ($s !== '' && !in_array($s, $out, true)) {
                    $out[] = $s;
                }
            }
        }
        return $out;
    }

    // ---- 内部 ----

    private function normalize(array $item): array
    {
        return [
            'rr' => $item['item'] ?? '@',
            'type' => $item['type'] ?? 'A',
            'value' => $item['value'] ?? '',
            'ttl' => (int)($item['ttl'] ?? 900),
            'line' => $this->unmapLine($item['line'] ?? ''),
            'mx' => (int)($item['level'] ?? 0),
            'status' => ((int)($item['pause'] ?? 0) === 1) ? 0 : 1,
            'remote_id' => (string)($item['id'] ?? ''),
        ];
    }

    private function mapLine(string $line): string
    {
        $map = [
            '默认' => '', '电信' => 'LTEL', '联通' => 'LCNC', '移动' => 'LMOB',
            '教育网' => 'LEDU', '搜索引擎' => 'LSEO', '境外' => 'LFOR',
        ];
        return $map[$line] ?? '';
    }

    private function unmapLine(string $line): string
    {
        $map = [
            '' => '默认', 'LTEL' => '电信', 'LCNC' => '联通', 'LMOB' => '移动',
            'LEDU' => '教育网', 'LSEO' => '搜索引擎', 'LFOR' => '境外',
        ];
        return $map[$line] ?? $line;
    }

    private function parseDate(string $str): string
    {
        // expdate 可能形如 "2027-01-01 12:00:00" 或 "2027-01-01"
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', trim($str), $m)) {
            return $m[0];
        }
        return '';
    }

    /**
     * 发起请求
     * @param string $act   动作名（getdomains/getdnsrecord/adddnsrecord/moddnsrecord/deldnsrecord）
     * @param array  $params 业务参数
     * @param string $method GET|POST
     */
    private function request(string $act, array $params, string $method = 'POST'): array
    {
        $username = $this->credentials['username'] ?? '';
        $apiPassword = $this->credentials['api_password'] ?? '';

        if ($username === '' || $apiPassword === '') {
            throw new \RuntimeException('西部数码凭证不完整（需 username + API 密码）');
        }

        $time = (int)round(microtime(true) * 1000); // 毫秒时间戳
        $token = md5($username . $apiPassword . $time);

        $params['act'] = $act;
        $params['username'] = $username;
        $params['time'] = $time;
        $params['token'] = $token;

        // 路径：/domain/?act=xxx  （接口均在 domain 子路径下）
        $path = '/domain/';

        if ($method === 'GET') {
            $url = self::ENDPOINT . $path . '?' . http_build_query($params);
            $resp = $this->httpGet($url);
        } else {
            $resp = $this->httpPost(self::ENDPOINT . $path, http_build_query($params), [
                'Content-Type: application/x-www-form-urlencoded',
            ]);
        }

        $body = $resp['body'];
        // 响应为 GBK，转 UTF-8
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'GBK');
        }
        $data = json_decode($body, true);

        if ($data === null) {
            throw new \RuntimeException('西部数码响应解析失败: ' . substr($body, 0, 200));
        }

        // result=200 表示成功
        $result = (int)($data['result'] ?? 0);
        if ($result !== 200) {
            $msg = $data['msg'] ?? $data['clientid'] ?? '未知错误';
            throw new \RuntimeException('西部数码: ' . $msg);
        }
        return $data;
    }
}
