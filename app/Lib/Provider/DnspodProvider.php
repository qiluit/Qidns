<?php

declare(strict_types=1);

namespace QiDNS\Lib\Provider;

/**
 * DNSPod 传统 API 适配器（dnsapi.cn）
 *
 * 认证：login_token = "ID,token"（例如 "655177,aa242d0f01b4bfb81656c51f4507c326"）
 * 接口：Domain.List / Record.List / Record.Create / Record.Modify / Record.Remove
 * 请求：POST https://dnsapi.cn/<Action>，公共参数 login_token + format=json
 * 返回：status.code == "1" 表示成功
 *
 * 注意：仅支持 DNSPod Token，仅限主账号调用。
 */
class DnspodProvider extends BaseProvider implements ProviderInterface
{
    private const ENDPOINT = 'https://dnsapi.cn';

    public function code(): string
    {
        return 'dnspod';
    }

    public function test(): bool
    {
        try {
            $this->call('Domain.List', ['type' => 'all', 'offset' => 0, 'length' => 1]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function listDomains(): array
    {
        $result = [];
        $offset = 0;
        $length = 100;
        do {
            $resp = $this->call('Domain.List', [
                'type' => 'all',
                'offset' => $offset,
                'length' => $length,
            ]);
            $list = $resp['domains'] ?? [];
            foreach ($list as $item) {
                $result[] = [
                    'domain' => $item['name'] ?? '',
                    'expire_at' => '', // 传统 API Domain.List 不含到期日，需 WHOIS 补充
                    'domain_id' => (string)($item['id'] ?? ''),
                ];
            }
            // 判断是否还有下一页
            if (count($list) < $length) {
                break;
            }
            $offset += $length;
        } while (!empty($list));

        return $result;
    }

    public function listRecords(string $domain): array
    {
        $result = [];
        $offset = 0;
        $length = 100;
        do {
            $resp = $this->call('Record.List', [
                'domain' => $domain,
                'offset' => $offset,
                'length' => $length,
            ]);
            $list = $resp['records'] ?? [];
            foreach ($list as $item) {
                $result[] = $this->normalize($item);
            }
            if (count($list) < $length) {
                break;
            }
            $offset += $length;
        } while (!empty($list));

        return $result;
    }

    public function createRecord(string $domain, array $record): string
    {
        $params = [
            'domain' => $domain,
            'sub_domain' => $record['rr'] === '@' ? '@' : $record['rr'],
            'record_type' => $record['type'],
            'value' => $record['value'],
            'record_line' => $this->mapLine($record['line'] ?? '默认'),
        ];
        if (isset($record['ttl'])) {
            $params['ttl'] = (int)$record['ttl'];
        }
        if (($record['type'] ?? '') === 'MX' && isset($record['mx'])) {
            $params['mx'] = (int)$record['mx'];
        }

        $resp = $this->call('Record.Create', $params);
        $id = (string)($resp['record']['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('DNSPod 未返回 record id');
        }
        return $id;
    }

    public function updateRecord(string $domain, string $remoteId, array $record): bool
    {
        $params = [
            'domain' => $domain,
            'record_id' => $remoteId,
            'sub_domain' => $record['rr'] === '@' ? '@' : $record['rr'],
            'record_type' => $record['type'],
            'value' => $record['value'],
            'record_line' => $this->mapLine($record['line'] ?? '默认'),
        ];
        if (isset($record['ttl'])) {
            $params['ttl'] = (int)$record['ttl'];
        }
        if (($record['type'] ?? '') === 'MX' && isset($record['mx'])) {
            $params['mx'] = (int)$record['mx'];
        }

        $this->call('Record.Modify', $params);
        return true;
    }

    public function deleteRecord(string $domain, string $remoteId): bool
    {
        $this->call('Record.Remove', [
            'domain' => $domain,
            'record_id' => $remoteId,
        ]);
        return true;
    }

    // ---- 内部 ----

    private function normalize(array $item): array
    {
        return [
            'rr' => $item['name'] ?? '@',
            'type' => $item['type'] ?? 'A',
            'value' => $item['value'] ?? '',
            'ttl' => (int)($item['ttl'] ?? 600),
            'line' => $this->unmapLine($item['line'] ?? '默认'),
            'mx' => (int)($item['mx'] ?? 0),
            'status' => (($item['enabled'] ?? '1') === '1') ? 1 : 0,
            'remote_id' => (string)($item['id'] ?? ''),
        ];
    }

    private function mapLine(string $line): string
    {
        // 传统 API 直接用中文线路名
        $map = [
            '默认' => '默认', '电信' => '电信', '联通' => '联通', '移动' => '移动',
            '教育网' => '教育网', '海外' => '境外', '搜索引擎' => '搜索引擎',
        ];
        return $map[$line] ?? '默认';
    }

    private function unmapLine(string $line): string
    {
        return $line;
    }

    /**
     * 调用传统 API
     * @param string $action Domain.List / Record.List / ...
     */
    private function call(string $action, array $params): array
    {
        $loginToken = trim((string)($this->credentials['login_token'] ?? ''));
        if ($loginToken === '') {
            // 兼容：若存的是 id + token 分开，则拼接
            $id = trim((string)($this->credentials['id'] ?? ''));
            $token = trim((string)($this->credentials['token'] ?? ''));
            if ($id !== '' && $token !== '') {
                $loginToken = $id . ',' . $token;
            }
        }
        if ($loginToken === '') {
            throw new \RuntimeException('DNSPod 凭证不完整（需 login_token）');
        }

        $params['login_token'] = $loginToken;
        $params['format'] = 'json';

        $resp = $this->httpPost(self::ENDPOINT . '/' . $action, http_build_query($params), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $data = json_decode($resp['body'], true);
        if ($data === null) {
            throw new \RuntimeException('DNSPod 响应解析失败: ' . substr($resp['body'], 0, 200));
        }

        // status.code == "1" 表示成功（注意是字符串）
        $code = (string)($data['status']['code'] ?? '');
        if ($code !== '1') {
            $msg = $data['status']['message'] ?? '未知错误';
            throw new \RuntimeException('DNSPod: ' . $msg . ' (code=' . $code . ')');
        }
        return $data;
    }
}
