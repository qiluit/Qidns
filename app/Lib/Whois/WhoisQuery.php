<?php

declare(strict_types=1);

namespace QiDNS\Lib\Whois;

/**
 * WHOIS 查询：域名到期日 + 注册商 + 证书到期日
 */
class WhoisQuery
{
    /**
     * 查询域名注册到期日与注册商
     * @return array ['expire_at' => 'YYYY-MM-DD', 'days_left' => int, 'registrar' => string, 'raw' => string]
     *               registrar 为归一化代码：aliyun/dnspod/west/other/''（查不到）
     */
    public static function domainExpire(string $domain): array
    {
        $result = ['expire_at' => '', 'days_left' => 0, 'registrar' => '', 'raw' => ''];
        $raw = self::queryWithReferral($domain);

        $result['raw'] = $raw;
        $date = self::extractExpireDate($raw);
        if ($date !== '') {
            $result['expire_at'] = $date;
            $ts = strtotime($date);
            if ($ts !== false) {
                $result['days_left'] = (int)floor(($ts - time()) / 86400);
            }
        }
        $result['registrar'] = self::extractRegistrar($raw);
        return $result;
    }

    /** 只查注册商（回填存量域名用），返回归一化代码 */
    public static function registrar(string $domain): string
    {
        return self::extractRegistrar(self::queryWithReferral($domain));
    }

    /**
     * 结构化 WHOIS 详情（供域名列「WHOIS」浮层展示）。
     * 首选 RDAP over HTTPS（IANA 官方 bootstrap，443 端口更稳、数据更全），
     * 失败自动回退标准 WHOIS 43 协议。两条通道均直连注册局/注册商，
     * 不经过任何我方 DNS/解析商 API，结果中立可信。
     *
     * @return array domain/source/registrar/registrar_name/expire_at/created_at/updated_at/days_left/nameservers/status
     */
    public static function whoisInfo(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $rdap = self::rdapLookup($domain);
        if ($rdap !== null) {
            return self::fromRdap($rdap, $domain);
        }
        return self::fromWhois(self::queryWithReferral($domain), $domain);
    }

    /**
     * 按域名后缀查询 WHOIS，并对新后缀 follow IANA 引导找到真正的注册局。
     *
     * 通用逻辑（不针对具体域名/后缀硬编码）：
     *  - .com/.net → verisign；.cn → cnnic；其余新后缀 → 先问 whois.iana.org，
     *    从返回里提取 refer:/whois: 字段指向的注册局服务器，再向它查询。
     *  - 引导服务器本身只给 referral 时，最多再 follow 一层，避免死循环。
     * 这样 .wang/.tech/.top/.xyz... 等任意新后缀都能拿到 Registrar 行。
     */
    private static function queryWithReferral(string $domain): string
    {
        $tld = strtolower((string)pathinfo($domain, PATHINFO_EXTENSION));

        // 老牌后缀：注册局明确，直接查
        if ($tld === 'cn') {
            return self::query('whois.cnnic.cn', 43, $domain);
        }
        if (in_array($tld, ['com', 'net'], true)) {
            $raw = self::query('whois.verisign-grs.com', 43, $domain);
            if ($raw === '' || !self::hasRegistryData($raw)) {
                $raw = self::query('whois.crsnic.net', 43, $domain);
            }
            return $raw;
        }

        // 新后缀：IANA 引导 → 真正的注册局
        $iana = self::query('whois.iana.org', 43, $domain);
        $refer = self::extractReferral($iana);
        if ($refer !== '' && $refer !== 'whois.iana.org') {
            $raw = self::query($refer, 43, $domain);
            // 少数注册局仍只返回下一跳 referral，再 follow 一层（排除自身，防环）
            if ($raw !== '' && !self::hasRegistryData($raw)) {
                $next = self::extractReferral($raw);
                if ($next !== '' && $next !== $refer && $next !== 'whois.iana.org') {
                    $raw = self::query($next, 43, $domain);
                }
            }
            if ($raw !== '') {
                return $raw;
            }
        }
        // 兜底：引导拿不到就退回 IANA 原文（至少可能有 expire 线索）
        return $iana;
    }

    /** WHOIS 文本是否含注册局权威数据（注册商行或到期日行），用于判断是否需要再 follow */
    private static function hasRegistryData(string $raw): bool
    {
        return (bool)preg_match('/^(?:Registrar|Sponsoring Registrar|Registrar Name)\s*:|Registration Time|Registry Expiry Date|^Expiration Time|^Expire Date/mi', $raw);
    }

    /** 从 WHOIS 文本提取 referral 引导服务器（IANA 的 refer:/whois: 字段） */
    private static function extractReferral(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        // 优先 registrar whois server，其次 refer/whois（IANA 两者通常一致）
        $patterns = [
            '/^registrar whois server\s*:\s*(\S+)/mi',
            '/^refer\s*:\s*(\S+)/mi',
            '/^whois\s*:\s*(\S+)/mi',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $raw, $m)) {
                $host = trim($m[1], " /\t\r\n");
                if ($host !== '' && strpos($host, '.') !== false) {
                    return strtolower($host);
                }
            }
        }
        return '';
    }

    /**
     * 从 WHOIS 文本提取注册商并归一化为服务商代码。
     * 兼容各注册局字段写法（Verisign 的 Registrar / CNNIC 的 Sponsoring Registrar / Registrar Name），
     * 用关键词映射覆盖三家常见英文与中文名。
     */
    private static function extractRegistrar(string $raw): string
    {
        return self::mapRegistrar(self::extractRegistrarName($raw));
    }

    /**
     * 提取注册商全名（原始字符串）。
     * 统一在此识别多种字段写法，避免 extractRegistrar / fromWhois 两处规则漂移：
     *  - Registrar:            通用（Verisign 及多数新后缀注册局）
     *  - Sponsoring Registrar: CNNIC（.cn/.ac.cn 等）标准字段，值为中文注册商名
     *  - Registrar Name:       少数注册局
     * 精确匹配「字段名 + 冒号」，不会误命中 Registrar WHOIS Server / URL / IANA ID 等衍生字段。
     */
    private static function extractRegistrarName(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $fields = ['Registrar', 'Sponsoring Registrar', 'Registrar Name'];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            foreach ($fields as $field) {
                if (preg_match('/^' . preg_quote($field, '/') . '\s*:\s*(.+)$/i', $line, $m)) {
                    $name = trim($m[1]);
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        }
        return '';
    }

    /**
     * 查询 HTTPS 证书到期日
     */
    public static function sslExpire(string $domain): array
    {
        $result = ['expire_at' => '', 'days_left' => 0, 'raw' => ''];

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'capture_peer_cert' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            'ssl://' . $domain . ':443',
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if (!$fp) {
            return $result;
        }

        $params = stream_context_get_params($fp);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        fclose($fp);

        if (!$cert) {
            return $result;
        }

        $info = openssl_x509_parse($cert);
        $validTo = $info['validTo_time_t'] ?? 0;
        if ($validTo > 0) {
            $result['expire_at'] = date('Y-m-d', $validTo);
            $result['days_left'] = (int)floor(($validTo - time()) / 86400);
            $result['raw'] = $info['subject']['CN'] ?? '';
        }
        return $result;
    }

    private static function query(string $host, int $port, string $domain): string
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$fp) {
            return '';
        }
        stream_set_timeout($fp, 10);
        fwrite($fp, $domain . "\r\n");
        $raw = '';
        while (!feof($fp)) {
            $raw .= fread($fp, 4096);
        }
        fclose($fp);
        return $raw;
    }

    private static function extractExpireDate(string $raw): string
    {
        // 常见格式：Registry Expiry Date: 2027-01-01T04:00:00Z
        //           Expiration Time: 2027-01-01
        //           Expire Date: 2027-01-01
        $patterns = [
            '/(?:Registry Expiry Date|Expiration Time|Expire Date|Expiry Date|expire)[\s:]+(\d{4})-(\d{2})-(\d{2})/i',
            '/(?:Registry Expiry Date|Expiration Time)[\s:]+(\d{4})\.(\d{2})\.(\d{2})/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $raw, $m)) {
                return $m[1] . '-' . $m[2] . '-' . $m[3];
            }
        }
        return '';
    }

    /* ==================== RDAP over HTTPS（WHOIS 首选通道） ==================== */

    /** 缓存目录（RDAP bootstrap 表） */
    private static function cacheDir(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/rdap';
    }

    /**
     * 从 IANA 官方 bootstrap（data.iana.org/rdap/dns.json）按 TLD 找 RDAP 服务器。
     * 表本地缓存 7 天，避免每次查询都访问 IANA。
     */
    private static function rdapServerFor(string $domain): string
    {
        $tld = strtolower((string)pathinfo($domain, PATHINFO_EXTENSION));
        if ($tld === '') {
            return '';
        }
        $dir = self::cacheDir();
        $file = $dir . '/dns.json';
        $json = '';
        if (is_file($file) && time() - filemtime($file) < 7 * 86400) {
            $json = @file_get_contents($file);
        }
        if ($json === '') {
            $json = self::httpGet('https://data.iana.org/rdap/dns.json', 8);
            if ($json !== '' && is_dir($dir)) {
                @file_put_contents($file, $json);
            }
        }
        if ($json === '') {
            return '';
        }
        $data = json_decode($json, true);
        foreach (($data['services'] ?? []) as $service) {
            $tlds = $service[0] ?? [];
            foreach ($tlds as $suffix) {
                if (strtolower((string)$suffix) === $tld) {
                    return (string)($service[1][0] ?? '');
                }
            }
        }
        return '';
    }

    /** RDAP 查询：GET {server}/domain/{domain}，返回关联数组或 null */
    private static function rdapLookup(string $domain): ?array
    {
        $server = self::rdapServerFor($domain);
        if ($server === '') {
            return null;
        }
        $url = rtrim($server, '/') . '/domain/' . rawurlencode($domain);
        $json = self::httpGet($url, 10, ['Accept: application/rdap+json']);
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        // RDAP 错误响应用 errorCode 而非 domain 字段，视为失败回退 WHOIS
        if (!is_array($data) || isset($data['errorCode']) || empty($data['ldhName'])) {
            return null;
        }
        return $data;
    }

    /** 轻量 HTTPS GET（curl 优先，带 CA 配置与超时；失败返回空串） */
    private static function httpGet(string $url, int $timeout = 10, array $headers = []): string
    {
        $cainfo = $GLOBALS['qidns_config']['curl_cainfo'] ?? '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($cainfo !== '' && is_file($cainfo)) {
                curl_setopt($ch, CURLOPT_CAINFO, $cainfo);
            }
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) {
                return '';
            }
            return (string)$body;
        }
        // 兜底：stream（同域名环境下 curl 一般存在）
        $ssl = ['verify_peer' => true];
        if ($cainfo !== '' && is_file($cainfo)) {
            $ssl['cafile'] = $cainfo;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'header' => implode("\r\n", $headers)],
            'ssl' => $ssl,
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? '' : $body;
    }

    /** RDAP JSON → 统一结构 */
    private static function fromRdap(array $d, string $domain): array
    {
        $out = self::emptyInfo($domain);
        $out['source'] = 'rdap';

        // 事件：registration / expiration / last changed
        foreach (($d['events'] ?? []) as $e) {
            $action = strtolower((string)($e['eventAction'] ?? ''));
            $date = self::normalizeDate((string)($e['eventDate'] ?? ''));
            if ($date === '') {
                continue;
            }
            if ($action === 'expiration') {
                $out['expire_at'] = $date;
            } elseif ($action === 'registration') {
                $out['created_at'] = $date;
            } elseif ($action === 'last changed' || $action === 'last update of rdap database') {
                $out['updated_at'] = $date;
            }
        }

        // 实体：找 role=registrar 的注册商，取 vcard fn
        foreach (($d['entities'] ?? []) as $ent) {
            $roles = array_map('strtolower', (array)($ent['roles'] ?? []));
            if (in_array('registrar', $roles, true)) {
                $out['registrar_name'] = self::vcardFn($ent['vcardArray'] ?? []);
                break;
            }
        }
        $out['registrar'] = self::mapRegistrar($out['registrar_name']);

        // 名称服务器
        foreach (($d['nameservers'] ?? []) as $ns) {
            $host = strtolower((string)($ns['ldhName'] ?? $ns['unicodeName'] ?? ''));
            if ($host !== '') {
                $out['nameservers'][] = rtrim($host, '.');
            }
        }
        $out['status'] = array_values(array_filter(array_map('strval', (array)($d['status'] ?? []))));
        $out['days_left'] = $out['expire_at'] !== ''
            ? (int)floor((strtotime($out['expire_at']) - time()) / 86400) : 0;
        return $out;
    }

    /** WHOIS 文本 → 统一结构（RDAP 失败时兜底） */
    private static function fromWhois(string $raw, string $domain): array
    {
        $out = self::emptyInfo($domain);
        $out['source'] = 'whois';
        $out['expire_at'] = self::extractExpireDate($raw);
        $out['days_left'] = $out['expire_at'] !== ''
            ? (int)floor((strtotime($out['expire_at']) - time()) / 86400) : 0;
        // 注册商全名统一走 extractRegistrarName（兼容 Registrar / Sponsoring Registrar / Registrar Name）
        $out['registrar_name'] = self::extractRegistrarName($raw);
        $out['registrar'] = self::mapRegistrar($out['registrar_name']);

        $created = '';
        $nameservers = [];
        $statuses = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if (preg_match('/^(?:Creation Date|Created Date|Registered on|Registration Time)\s*:\s*(.+)$/i', $line, $m)) {
                $created = self::normalizeDate(trim($m[1]));
            } elseif (preg_match('/^Name Server\s*:\s*(\S+)/i', $line, $m)) {
                $ns = strtolower(rtrim(trim($m[1]), '.'));
                if ($ns !== '' && !in_array($ns, $nameservers, true)) {
                    $nameservers[] = $ns;
                }
            } elseif (preg_match('/^Domain Status\s*:\s*(\S+)/i', $line, $m)) {
                $statuses[] = trim($m[1]);
            }
        }
        $out['created_at'] = $created;
        $out['nameservers'] = $nameservers;
        $out['status'] = array_values(array_unique($statuses));
        return $out;
    }

    /** 空结果模板 */
    private static function emptyInfo(string $domain): array
    {
        return [
            'domain' => $domain, 'source' => '',
            'registrar' => '', 'registrar_name' => '',
            'expire_at' => '', 'created_at' => '', 'updated_at' => '', 'days_left' => 0,
            'nameservers' => [], 'status' => [],
        ];
    }

    /** 从 RDAP vcardArray 取 fn（全名） */
    private static function vcardFn(array $vcardArray): string
    {
        $rows = $vcardArray[1] ?? [];
        foreach ($rows as $row) {
            if (is_array($row) && strtolower((string)($row[0] ?? '')) === 'fn') {
                return trim((string)($row[3] ?? ''));
            }
        }
        return '';
    }

    /** 日期归一化为 YYYY-MM-DD（兼容 ISO8601 / 点分隔 / 中文） */
    private static function normalizeDate(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if (preg_match('/(\d{4})[-.\/年](\d{1,2})[-.\/月](\d{1,2})/', $s, $m)) {
            return $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[3], 2, '0', STR_PAD_LEFT);
        }
        return '';
    }

    /**
     * 注册商名称 → 归一化代码。复用已有映射规则，供 RDAP/WHOIS 两条通道共用。
     */
    private static function mapRegistrar(string $name): string
    {
        if ($name === '') {
            return '';
        }
        $s = strtolower($name);
        if (strpos($s, 'alibaba') !== false || strpos($s, 'aliyun') !== false
            || strpos($s, 'hichina') !== false || strpos($s, 'wanwang') !== false
            || strpos($s, 'net.cn') !== false
            || strpos($name, '阿里云') !== false || strpos($name, '万网') !== false) {
            return 'aliyun';
        }
        if (strpos($s, 'tencent') !== false || strpos($s, 'dnspod') !== false || strpos($s, 'qcloud') !== false
            || strpos($name, '腾讯') !== false) {
            return 'dnspod';
        }
        if (strpos($s, 'west263') !== false || strpos($s, 'west.cn') !== false || strpos($s, 'xinnet') !== false
            || strpos($name, '西维数码') !== false || strpos($name, '西部数码') !== false) {
            return 'west';
        }
        return 'other';
    }
}
