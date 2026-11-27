# QiDNS

集中管理腾讯云 DNSPod、阿里云、西部数码的域名与解析记录。支持域名状态与到期日归集、DNS 托管平台识别、解析记录增删改与快照回滚、标签与模板批量操作、到期巡检。

## 环境

- PHP 7.4+（已在 7.4 / 8.3 验证），需要 PDO MySQL、OpenSSL、cURL
- MySQL 5.7+ / MariaDB
- Web 服务器指向项目根目录

## 安装

1. 将代码部署到站点根目录，确保 `app/`、`assets/`、`install/` 可读写。
2. 访问 `/install/index.php`：填写数据库连接，系统自动建表并生成 `config.php`，再创建管理员账号。
3. 安装完成后删除 `install/` 目录。
4. 如需定时任务，配置计划任务每天执行一次：`php cron/expire_check.php`。

## 配置

`config.sample.php` 是配置模板。实际配置在 `config.php`，

- `app_key`：安装时随机生成，用于 AES-256-CBC 加密服务商凭证。请备份；丢失后库里已保存的凭证无法解出。
- `db`：数据库连接信息。
- `curl_cainfo`：证书路径。留空使用系统默认 CA；Windows 环境可填实际 `cacert.pem` 绝对路径。

## 服务商凭证

在后台「服务商账号」中添加阿里云 / 腾讯云 / 西部数码账号。凭证经 `app_key` 加密后存入数据库，页面只展示脱敏后的值；`config.php` 和数据库备份都不要放进版本库。

## 目录

- `app/Controllers` 控制器；`app/Lib/Provider` 各服务商适配器；`app/Lib/Whois` 注册局查询；`app/Views` 视图
- `assets/` 前端 CSS / JS
- `cron/` 到期巡检与关联表重建脚本（`php cron/rebuild_zones.php` 可重复执行）
- `install/` 安装向导与数据库结构
