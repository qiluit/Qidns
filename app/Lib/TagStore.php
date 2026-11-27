<?php

declare(strict_types=1);

namespace QiDNS\Lib;

use QiDNS\Core\DB;

/**
 * 标签字典（qd_tag）
 *
 * 事实前提：标签本身是「域名的附属属性」，真实关系仍存在 qd_domain.tags（逗号分隔）。
 * 但只有那份字符串，就做不到「改名一次全改」「删掉标签不留僵尸」「看有多少标签在用」，
 * 所以这里维护一份字典表：名字、颜色、排序。
 *
 * 一致性约定：
 *  - 标签名不允许含逗号（与 qd_domain.tags 的分隔符冲突）；
 *  - 改名/删除必须同步改写 qd_domain.tags，避免字典与域名数据打架；
 *  - 域名上出现而字典里没有的标签（历史数据、外部直接改库），all() 时自动补登记。
 */
class TagStore
{
    /** 预设色板：浅色药丸 + 同色系深字，与全站 chip 风格一致 */
    public const COLORS = ['blue', 'green', 'orange', 'purple', 'pink', 'teal', 'gray', 'red'];

    public const DEFAULT_COLOR = 'blue';

    private static $ready = false;

    /** 单请求内缓存 all()（域名列表会连续调 names + colorMap） */
    private static $cache = null;

    /** 建表（幂等） */
    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        $exists = (int)DB::val(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['qd_tag']
        );
        if ($exists === 0) {
            DB::exec(
                "CREATE TABLE IF NOT EXISTS qd_tag (
                    id int(11) unsigned NOT NULL AUTO_INCREMENT,
                    name varchar(64) NOT NULL,
                    color varchar(16) NOT NULL DEFAULT 'blue',
                    sort int(11) NOT NULL DEFAULT 0,
                    created_at datetime NOT NULL,
                    updated_at datetime NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }
        self::$ready = true;
    }

    /** 全部标签（含使用数量）；域名上出现但字典缺失的标签会先补登记 */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::ensure();
        self::importFromDomains();

        $usage = self::usage();
        $rows = DB::all('SELECT id, name, color, sort, created_at, updated_at FROM qd_tag ORDER BY sort ASC, id ASC');
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['sort'] = (int)$r['sort'];
            $r['use_count'] = $usage[$r['name']] ?? 0;
        }
        unset($r);
        self::$cache = $rows;
        return $rows;
    }

    /** 只取名字（筛选下拉、模板建议） */
    public static function names(): array
    {
        return array_map(static function ($r) {
            return (string)$r['name'];
        }, self::all());
    }

    /** name => color，供视图给 chip 上色 */
    public static function colorMap(): array
    {
        $out = [];
        foreach (self::all() as $r) {
            $out[(string)$r['name']] = (string)$r['color'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        self::ensure();
        return DB::one('SELECT * FROM qd_tag WHERE id = ?', [$id]);
    }

    /** 新建标签，返回新行 */
    public static function create(string $name, string $color = ''): array
    {
        self::ensure();
        $name = self::normalizeName($name);
        if ($name === '') {
            throw new \RuntimeException('标签名不能为空');
        }
        if (DB::one('SELECT id FROM qd_tag WHERE name = ?', [$name])) {
            throw new \RuntimeException('已存在同名标签「' . $name . '」');
        }
        $now = date('Y-m-d H:i:s');
        $sort = (int)DB::val('SELECT COALESCE(MAX(sort), 0) FROM qd_tag') + 1;
        $id = DB::insert(
            'INSERT INTO qd_tag (name, color, sort, created_at, updated_at) VALUES (?,?,?,?,?)',
            [$name, self::normalizeColor($color), $sort, $now, $now]
        );
        self::$cache = null;
        return ['id' => $id, 'name' => $name, 'color' => self::normalizeColor($color)];
    }

    /** 改名 / 换色，改名会同步改写所有域名的 tags 字段 */
    public static function update(int $id, string $name, string $color): array
    {
        self::ensure();
        $row = self::find($id);
        if (!$row) {
            throw new \RuntimeException('标签不存在');
        }
        $name = self::normalizeName($name);
        if ($name === '') {
            throw new \RuntimeException('标签名不能为空');
        }
        if (DB::one('SELECT id FROM qd_tag WHERE name = ? AND id <> ?', [$name, $id])) {
            throw new \RuntimeException('已存在同名标签「' . $name . '」');
        }
        $color = self::normalizeColor($color);
        $old = (string)$row['name'];
        $now = date('Y-m-d H:i:s');

        DB::begin();
        try {
            DB::exec('UPDATE qd_tag SET name=?, color=?, updated_at=? WHERE id=?', [$name, $color, $now, $id]);
            if ($old !== $name) {
                self::replaceInDomains($old, $name, $now);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }
        self::$cache = null;
        return ['id' => $id, 'name' => $name, 'color' => $color, 'renamed_from' => $old];
    }

    /** 删除标签，并从所有域名上摘掉 */
    public static function delete(int $id): array
    {
        self::ensure();
        $row = self::find($id);
        if (!$row) {
            throw new \RuntimeException('标签不存在');
        }
        $name = (string)$row['name'];
        $now = date('Y-m-d H:i:s');

        DB::begin();
        try {
            $affected = self::removeFromDomains($name, $now);
            DB::exec('DELETE FROM qd_tag WHERE id = ?', [$id]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }
        self::$cache = null;
        return ['id' => $id, 'name' => $name, 'affected' => $affected];
    }

    /**
     * 把「域名里已经存在、字典里还没有」的标签补登记（历史数据迁移，幂等）。
     * @return int 新增条数
     */
    public static function importFromDomains(): int
    {
        self::ensure();
        $usage = self::usage();
        if (!$usage) {
            return 0;
        }
        $known = [];
        foreach (DB::all('SELECT name FROM qd_tag') as $r) {
            $known[(string)$r['name']] = true;
        }
        $missing = array_diff(array_keys($usage), array_keys($known));
        if (!$missing) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $sort = (int)DB::val('SELECT COALESCE(MAX(sort), 0) FROM qd_tag');
        $n = 0;
        foreach ($missing as $name) {
            $name = (string)$name;
            if ($name === '' || mb_strlen($name) > 64) {
                continue;
            }
            $sort++;
            DB::exec(
                'INSERT IGNORE INTO qd_tag (name, color, sort, created_at, updated_at) VALUES (?,?,?,?,?)',
                [$name, self::DEFAULT_COLOR, $sort, $now, $now]
            );
            $n++;
        }
        return $n;
    }

    /** 每个标签被多少域名使用 */
    public static function usage(): array
    {
        $rows = DB::all("SELECT tags FROM qd_domain WHERE tags <> ''");
        $count = [];
        foreach ($rows as $r) {
            foreach (explode(',', (string)$r['tags']) as $t) {
                $t = trim($t);
                if ($t !== '') {
                    $count[$t] = ($count[$t] ?? 0) + 1;
                }
            }
        }
        return $count;
    }

    /** 标签名规范化：去空白、去逗号（分隔符）、限长 */
    public static function normalizeName(string $name): string
    {
        $name = str_replace(['，', ','], ' ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        return mb_substr($name, 0, 64);
    }

    public static function normalizeColor(string $color): string
    {
        return in_array($color, self::COLORS, true) ? $color : self::DEFAULT_COLOR;
    }

    /** 域名列表里的标签名规范化（供 updateTags / removeTag 复用） */
    public static function normalizeList(string $tags): array
    {
        $parts = [];
        foreach (explode(',', $tags) as $t) {
            $t = trim($t);
            if ($t !== '' && !in_array($t, $parts, true)) {
                $parts[] = $t;
            }
        }
        return $parts;
    }

    /** 字典里的旧名批量替换成新名（同名去重） */
    private static function replaceInDomains(string $old, string $new, string $now): int
    {
        $rows = DB::all('SELECT id, tags FROM qd_domain WHERE FIND_IN_SET(?, tags)', [$old]);
        $n = 0;
        foreach ($rows as $r) {
            $parts = [];
            foreach (self::normalizeList((string)$r['tags']) as $t) {
                if ($t === $old) {
                    $t = $new;
                }
                if (!in_array($t, $parts, true)) {
                    $parts[] = $t;
                }
            }
            DB::exec('UPDATE qd_domain SET tags=?, updated_at=? WHERE id=?',
                [implode(',', $parts), $now, (int)$r['id']]);
            $n++;
        }
        return $n;
    }

    private static function removeFromDomains(string $name, string $now): int
    {
        $rows = DB::all('SELECT id, tags FROM qd_domain WHERE FIND_IN_SET(?, tags)', [$name]);
        $n = 0;
        foreach ($rows as $r) {
            $parts = array_values(array_filter(
                self::normalizeList((string)$r['tags']),
                static function ($t) use ($name) {
                    return $t !== $name;
                }
            ));
            DB::exec('UPDATE qd_domain SET tags=?, updated_at=? WHERE id=?',
                [implode(',', $parts), $now, (int)$r['id']]);
            $n++;
        }
        return $n;
    }
}
