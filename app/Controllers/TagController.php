<?php

declare(strict_types=1);

namespace QiDNS\Controllers;

use QiDNS\Core\DB;
use QiDNS\Core\Request;
use QiDNS\Core\Response;
use QiDNS\Core\View;
use QiDNS\Lib\Audit;
use QiDNS\Lib\TagStore;

/**
 * 标签管理：字典维护（增删改查）+ 使用情况
 *
 * 关系数据仍在 qd_domain.tags；本页改的是字典（名字、颜色、排序），
 * 改名与删除会自动同步到域名标签，避免出现「字典里没了、域名上还在」的僵尸标签。
 */
class TagController
{
    public function index(): void
    {
        $tags = TagStore::all();
        // 使用数量倒序一眼看出哪些标签是主力；0 使用量的排后面（新建待用）
        usort($tags, static function ($a, $b) {
            if ($a['use_count'] !== $b['use_count']) {
                return $b['use_count'] <=> $a['use_count'];
            }
            return $a['sort'] <=> $b['sort'];
        });

        $total = count($tags);
        $used = 0;
        $links = 0;
        foreach ($tags as $t) {
            if ($t['use_count'] > 0) {
                $used++;
                $links += (int)$t['use_count'];
            }
        }

        echo View::render('tag/index', [
            'tags' => $tags,
            'colors' => TagStore::COLORS,
            'stat' => [
                'total' => $total,
                'used' => $used,
                'idle' => $total - $used,
                'links' => $links,
                'domains' => (int)DB::val('SELECT COUNT(*) FROM qd_domain'),
            ],
        ]);
    }

    public function create(): void
    {
        try {
            $tag = TagStore::create(
                (string)Request::post('name', ''),
                (string)Request::post('color', '')
            );
        } catch (\Throwable $e) {
            Response::fail($e->getMessage());
        }
        Audit::log('create_tag', (string)$tag['name'], ['color' => $tag['color']]);
        Response::ok($tag, '标签已创建');
    }

    public function update(): void
    {
        $id = (int)Request::post('id', 0);
        try {
            $tag = TagStore::update(
                $id,
                (string)Request::post('name', ''),
                (string)Request::post('color', '')
            );
        } catch (\Throwable $e) {
            Response::fail($e->getMessage());
        }
        Audit::log('update_tag', (string)$tag['name'], [
            'from' => $tag['renamed_from'] ?? $tag['name'],
            'color' => $tag['color'],
        ]);
        $msg = ($tag['renamed_from'] ?? $tag['name']) !== $tag['name']
            ? '标签已改名，域名上的旧标签同步更新'
            : '标签已更新';
        Response::ok($tag, $msg);
    }

    public function delete(): void
    {
        $id = (int)Request::post('id', 0);
        try {
            $r = TagStore::delete($id);
        } catch (\Throwable $e) {
            Response::fail($e->getMessage());
        }
        Audit::log('delete_tag', (string)$r['name'], ['affected_domains' => $r['affected']]);
        Response::ok($r, $r['affected'] > 0
            ? '标签已删除，并从 ' . $r['affected'] . ' 个域名上移除'
            : '标签已删除');
    }
}
