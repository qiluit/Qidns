<?php

declare(strict_types=1);

namespace QiDNS\Core;

/**
 * 视图渲染（轻量，PHP 模板）
 */
class View
{
    private static $layout = 'layout';

    public static function render(string $template, array $data = [], bool $withLayout = true): string
    {
        $viewFile = APP_ROOT . '/app/Views/' . $template . '.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException('视图不存在: ' . $template);
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if ($withLayout && self::$layout !== null) {
            $layoutFile = APP_ROOT . '/app/Views/' . self::$layout . '.php';
            if (is_file($layoutFile)) {
                ob_start();
                require $layoutFile;
                return ob_get_clean();
            }
        }
        return $content;
    }

    public static function setLayout(?string $layout): void
    {
        self::$layout = $layout;
    }

    /** HTML 转义 */
    public static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 生成表头排序链接。当前列再次点击时切换正/倒序，并透传额外检索参数。
     * $curOrder 大小写均可（asc/desc、ASC/DESC）。
     */
    public static function sortLink(string $route, string $field, string $curSort, string $curOrder, array $extra = []): string
    {
        $isAsc = strtoupper($curOrder) === 'ASC';
        $next = ($curSort === $field && $isAsc) ? 'desc' : 'asc';
        // r/sort/order 放在后面，防止 extra 中的同名键污染路由
        $q = array_merge($extra, [
            'r' => $route,
            'sort' => $field,
            'order' => $next,
        ]);
        return '/index.php?' . http_build_query($q);
    }

    /**
     * 表头排序方向指示：默认显示上下双三角（提示可排序），
     * 当前排序列高亮对应方向的三角，另一个淡化；hover 时整体变主色。
     * 用 CSS border 画三角，零依赖、与字重/行高无关。
     */
    public static function sortArrow(string $field, string $curSort, string $curOrder): string
    {
        if ($curSort !== $field) {
            $state = 'idle'; // 未排序：上下都淡
        } else {
            $state = strtoupper($curOrder) === 'ASC' ? 'asc' : 'desc';
        }
        return '<span class="sort-ico ' . $state . '" aria-hidden="true">'
            . '<i class="up"></i><i class="down"></i></span>';
    }

    /**
     * 注册商标签：按「是否我方确认持有」分两种视觉。
     * - registrar 有值 = 我方注册商账号确认持有 → 厂商实色 chip
     * - registrar 空、whois_registrar 有值 = WHOIS 查到注册商但不在我方持有
     *   （客户域名仅托管解析等）→ 灰色虚线 chip，悬停说明
     * - 两者都空 → 破折号
     * $labels 为 provider 代码到中文名的映射。
     */
    public static function registrarChip(string $registrar, string $whoisRegistrar, array $labels): string
    {
        $labelOf = static function (string $code) use ($labels): string {
            return $labels[$code] ?? ($code === 'other' ? '其他注册商' : $code);
        };
        if ($registrar !== '') {
            $cls = in_array($registrar, ['dnspod', 'aliyun', 'west', 'other'], true)
                ? 'chip-' . $registrar : 'chip-other';
            return '<span class="chip ' . $cls . '">' . self::e($labelOf($registrar)) . '</span>';
        }
        if ($whoisRegistrar !== '') {
            $title = 'WHOIS 显示为「' . $labelOf($whoisRegistrar)
                . '」注册商，但不在我方该注册商账号下（如客户域名仅委托我方解析），故以灰色标识';
            return '<span class="chip chip-foreign" title="' . self::e($title) . '">'
                . self::e($labelOf($whoisRegistrar)) . '</span>';
        }
        return '<span class="muted">—</span>';
    }

    /**
     * 标签单元格内容（域名列表 / 按在用 DNS 分组共用）。
     *
     * 展示规则：行内只铺 2 个标签，其余收进 .tag-extra，点 » 在单元格内向下展开（行高增加）。
     * 交互（单击筛选、双击移除）由 assets/js/app.js 统一委托处理，视图不重复写脚本。
     *
     * @param array  $tags      该域名的标签名列表
     * @param int    $domainId  域名 ID（双击移除时定位）
     * @param array  $colors    name => 颜色 key（TagStore::colorMap()）
     * @param string $extraBtn  追加在行尾的按钮 HTML（如域名列表的「标」）
     */
    public static function tagCell(array $tags, int $domainId, array $colors, string $extraBtn = ''): string
    {
        $chip = static function (string $t) use ($colors, $domainId): string {
            $color = $colors[$t] ?? 'blue';
            return '<a class="chip chip-tag tag-c-' . self::e($color) . ' tag-chip"'
                . ' href="/index.php?r=Domain&tag=' . urlencode($t) . '"'
                . ' data-domain-id="' . $domainId . '"'
                . ' data-tag="' . self::e($t) . '"'
                . ' title="' . self::e($t . ' · 单击按此标签筛选，双击从该域名移除') . '">'
                . self::e($t) . '</a>';
        };

        $head = array_slice($tags, 0, 2);
        $rest = array_slice($tags, 2);

        $out = '<div class="tag-line"><span class="tag-list">';
        if (empty($head)) {
            $out .= '<span class="muted">—</span>';
        }
        foreach ($head as $t) {
            $out .= $chip((string)$t);
        }
        $out .= '</span>';

        if (!empty($rest)) {
            $out .= '<button type="button" class="tag-more" data-count="' . count($rest) . '"'
                 . ' onclick="toggleTagRow(this)" title="展开其余 ' . count($rest) . ' 个标签">'
                 . '» ' . count($rest) . '</button>';
        }
        $out .= $extraBtn . '</div>';

        if (!empty($rest)) {
            $out .= '<div class="tag-extra" hidden>';
            foreach ($rest as $t) {
                $out .= $chip((string)$t);
            }
            $out .= '</div>';
        }
        return $out;
    }
}
