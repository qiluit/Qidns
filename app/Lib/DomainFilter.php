<?php

declare(strict_types=1);

namespace QiDNS\Lib;

use QiDNS\Core\DB;

/**
 * 域名列表筛选公共能力
 * - 后缀（顶级域，如 com/cn/net）选项：从库里实际数据自适应收集，按使用量降序
 * - 复合后缀（如 com.cn）按最后两段识别，归属到完整的公共后缀，避免都挤进 cn
 */
class DomainFilter
{
    /** 需要按两段识别的常见复合后缀 */
    private const COMPOUND_SUFFIXES = [
        'com.cn', 'net.cn', 'org.cn', 'edu.cn', 'gov.cn', 'ac.cn',
        'com.hk', 'net.hk', 'org.hk', 'edu.hk', 'gov.hk', 'idv.hk',
        'com.tw', 'net.tw', 'org.tw', 'edu.tw', 'idv.tw',
        'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'go.jp',
        'co.kr', 'or.kr', 'ne.kr',
        'com.sg', 'com.au', 'net.au', 'org.au', 'edu.au',
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk',
        'com.br', 'com.mx', 'com.ar', 'co.nz', 'com.tr', 'co.in',
    ];

    /**
     * 从完整域名提取后缀（小写）。
     * 优先匹配复合后缀（最后两段），否则取最后一段。
     */
    public static function suffixOf(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }
        $parts = explode('.', $domain);
        if (count($parts) >= 3) {
            $lastTwo = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
            if (in_array($lastTwo, self::COMPOUND_SUFFIXES, true)) {
                return $lastTwo;
            }
        }
        return $parts[count($parts) - 1];
    }

    /**
     * 统计全库后缀分布，返回按数量降序的选项列表。
     * 每项：['value' => 'com', 'label' => '.com', 'count' => 12]
     */
    public static function suffixOptions(): array
    {
        $rows = DB::all("SELECT domain FROM qd_domain WHERE domain != ''");
        $counter = [];
        foreach ($rows as $r) {
            $suffix = self::suffixOf((string)$r['domain']);
            if ($suffix !== '') {
                $counter[$suffix] = ($counter[$suffix] ?? 0) + 1;
            }
        }
        arsort($counter, SORT_NUMERIC);
        $options = [];
        foreach ($counter as $suffix => $count) {
            $options[] = [
                'value' => $suffix,
                'label' => '.' . $suffix,
                'count' => $count,
            ];
        }
        return $options;
    }
}
