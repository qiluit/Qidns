-- QiDNS 数据库结构（MySQL 5.5 兼容）
-- 说明：5.5 无 JSON 类型，结构化数据统一用 TEXT 存 JSON；索引键前缀 ≤191 字符以兼容 utf8mb4

SET NAMES utf8mb4;

-- 管理员用户
CREATE TABLE IF NOT EXISTS `qd_user` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'password_hash 结果',
  `nickname` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DNS 服务商账号（凭证 AES 加密存储）
CREATE TABLE IF NOT EXISTS `qd_account` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL COMMENT '自定义账号名',
  `provider` varchar(16) NOT NULL COMMENT 'dnspod(含传统Token+腾讯云API3两套凭证)|aliyun|west',
  `credentials` text NOT NULL COMMENT 'AES 加密后的 JSON 凭证',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `last_sync_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 域名（全局唯一：域名字符串去重；注册商与 DNS 托管商分离存储）
CREATE TABLE IF NOT EXISTS `qd_domain` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `registrar` varchar(16) NOT NULL DEFAULT '' COMMENT '我方确认持有的注册商 provider 代码（west/aliyun/dnspod），未知为空',
  `whois_registrar` varchar(16) NOT NULL DEFAULT '' COMMENT 'WHOIS 注册商代码（aliyun/dnspod/west/other），registrar 为空时用于显示灰色标签',
  `registrar_account_id` int(11) unsigned NOT NULL DEFAULT '0',
  `dns_provider` varchar(16) NOT NULL DEFAULT '' COMMENT 'DNS托管商 provider 代码（dnspod/aliyun/west）',
  `dns_account_id` int(11) unsigned NOT NULL DEFAULT '0',
  `tags` varchar(255) NOT NULL DEFAULT '' COMMENT '标签，逗号分隔',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `expire_at` date DEFAULT NULL COMMENT '域名到期日',
  `domain_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '注册商侧状态 0未知 1急需续费 2急需赎回 3正常',
  `effective_dns` varchar(16) NOT NULL DEFAULT '' COMMENT '注册局实际在用DNS服务商(NS实证):dnspod/aliyun/west/other,空=查无NS',
  `ns_servers` varchar(255) NOT NULL DEFAULT '' COMMENT '注册局NS主机列表,逗号分隔',
  `ns_checked_at` datetime DEFAULT NULL COMMENT '最近一次NS实证时间',
  `is_stale` tinyint(1) NOT NULL DEFAULT '0' COMMENT '失效域名 1=两菜单均排除',
  `stale_reason` varchar(64) NOT NULL DEFAULT '' COMMENT '失效判定依据',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`(191)),
  KEY `idx_registrar` (`registrar`),
  KEY `idx_dns` (`dns_provider`),
  KEY `idx_stale` (`is_stale`),
  KEY `idx_effective` (`effective_dns`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 域名 ↔ DNS 解析平台关联（一家平台一行，互不覆盖）
-- 同一域名可同时挂在多家平台；是否生效以 qd_domain.effective_dns（NS 实证）为准
CREATE TABLE IF NOT EXISTS `qd_domain_zone` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(11) unsigned NOT NULL,
  `account_id` int(11) unsigned NOT NULL,
  `provider` varchar(16) NOT NULL DEFAULT '' COMMENT '逻辑服务商 dnspod/aliyun/west',
  `last_seen_at` datetime DEFAULT NULL COMMENT '最近一次同步确认该 zone 存在的时间',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain_account` (`domain_id`, `account_id`),
  KEY `idx_domain` (`domain_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 解析记录（本地缓存）
CREATE TABLE IF NOT EXISTS `qd_record` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(11) unsigned NOT NULL,
  `domain_id` int(11) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `rr` varchar(128) NOT NULL DEFAULT '@' COMMENT '主机记录',
  `type` varchar(10) NOT NULL COMMENT 'A|AAAA|CNAME|MX|TXT|NS|SRV|CAA',
  `value` text NOT NULL,
  `ttl` int(11) NOT NULL DEFAULT '600',
  `line` varchar(64) NOT NULL DEFAULT '默认',
  `mx` int(11) NOT NULL DEFAULT '0' COMMENT 'MX 优先级',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `remote_id` varchar(128) NOT NULL DEFAULT '' COMMENT '服务商侧记录 ID',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain_id`),
  KEY `idx_rr` (`rr`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 域名解析快照（用于回滚）
CREATE TABLE IF NOT EXISTS `qd_snapshot` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(11) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `provider` varchar(16) NOT NULL,
  `content` mediumtext NOT NULL COMMENT 'JSON：该域名全部记录',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 操作审计日志
CREATE TABLE IF NOT EXISTS `qd_audit` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL DEFAULT '0',
  `username` varchar(64) NOT NULL DEFAULT '',
  `action` varchar(32) NOT NULL COMMENT 'login|add_record|update_record|delete_record|sync|rollback|...',
  `target` varchar(255) NOT NULL DEFAULT '' COMMENT '目标描述，如 example.com / www',
  `detail` text COMMENT 'JSON：变更前后详情',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 标签字典（真实关系仍是 qd_domain.tags，这里管名字/颜色/排序）
CREATE TABLE IF NOT EXISTS `qd_tag` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `color` varchar(16) NOT NULL DEFAULT 'blue',
  `sort` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 解析模板
CREATE TABLE IF NOT EXISTS `qd_template` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `content` mediumtext NOT NULL COMMENT 'JSON：记录组合数组',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 系统设置
CREATE TABLE IF NOT EXISTS `qd_setting` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `skey` varchar(64) NOT NULL,
  `svalue` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
