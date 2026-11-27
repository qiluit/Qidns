<?php

declare(strict_types=1);

namespace QiDNS\Controllers;

use QiDNS\Core\Response;

/**
 * 解析管理（兼容入口）
 *
 * 解析管理已并入「域名管理 → 按在用 DNS 分组」视图，查询逻辑集中在 DomainController。
 * 本控制器保留仅为兼容旧链接/书签：把请求原样重定向到新视图，所有筛选参数透传。
 */
class HostingController
{
    public function index(): void
    {
        $query = $_GET;
        unset($query['r']);
        $query['r'] = 'Domain';
        $query['view'] = 'hosting';
        Response::redirect('/index.php?' . http_build_query($query));
    }
}
