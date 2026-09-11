# Admin QA Checklist

Generated scope: `node admin-i18n/check-qa.mjs` checks that this list still covers
`umi.js@<id>` every module found in the bundles, so a new route cannot silently avoid
qa.

For each row mark `[x]` in the Pass column when the row's states have been exercised
in a real browser at `/admin` with no Chinese in the rendered DOM, tooltips, or
toasts. The spec's "no Chinese text remains in the admin UI" is the conjunction of
`node admin-i18n/guard.mjs` exiting 0 AND this checklist marked pass for every entry.

| Pass | Area | Route / surface | States to exercise |
|------|------|-----------------|--------------------|
| [ ] | Dashboard | `/dashboard` (+ wrapper `/`) | nav + metrics cards (今日收入/上月收入/在线人数/实时注册), queue health banner ("当前队列服务运行异常…"), 7-day chart + top-user / top-node tables |
| [ ] | Users | `/admin/user` | list + pagination (条/页/跳至/页/上一页/下一页/向前 N 页), filters (邮箱/用户ID/封禁/管理员/流量/到期时间/关键词 — verify "Fuzzy" option label + that filtering works), actions dropdown (分配订单/删除用户/发送邮件/复制订阅URL/导出CSV/批量删除/批量封禁/过滤器/重置UUID及订阅URL + Tips flow), add/edit/confirm/ban/reset/info dialogs (including concatenated "...的安全信息吗？"), empty table, validation errors |
| [ ] | Users import | `/admin/user` (generate helpers) | CSV export headers, batch generation wizard (邮箱//账号页) |
| [ ] | Orders | `/admin/order` | list, filters, add/import flows, status pills (待确认/发放中/已发放/已驳回), detail drawers |
| [ ] | Subscriptions | `/admin/plan` | list, grid + "统计(语)", pricing tier labels (月付/季付/半年付/年付/两年付/三年付/一次性), add/edit drag ordering, renew toggle, traffic-reset method + "勾选后变更…", force-update switch |
| [ ] | Coupons | `/admin/coupon` | list, generate wizard (discount type/ratio/amount, limits per user/total/period/subscription placeholders), type label rendering ("按比例优惠"/"按金额优惠" -> Discount by ratio / by amount), generate/refresh/delete + CSV export |
| [ ] | Gift cards | `/admin/giftcard` | list, create wizard (type selector: 增加账户余额/增加订阅时长/增加套餐流量/… + 兑换订阅套餐 + 天/流量/重置/套餐 suffixes), generate/batch, confirm, empty state |
| [ ] | Servers / Nodes | `/server/manage` + `/server/group` (group CRUD) + per-protocol node lists under `/server/manage` (Shadowsocks/VMess/VLESS/Trojan/AnyTLS/Hysteria/TUIC — "添加" + save with enabling/rate-limit/TLS config + connection-verify handling) + route rules at `/server/route` (匹配输入/域名过滤器/协议过滤器/Xray出站配置 + 禁止访问(ip/端口/协议/域) + 指定(dns/出站ip/出站域)/自定义默认出站 + route group/match count) | sort dialog ("节点排序还没有保存" + 保存排序) |
| [ ] | Routes | `/admin/server/route` | creation wizard (route group / match group / action type selects, options with 中/低/高 priority) |
| [ ] | Payment config | `/config/payment` at `/admin/config/payment` | method list + enabling, add/edit form per gateway (显示名称/图标URL/固定手续费/百分比手续费/网关的通知将会发送到该域名… add-on notes), success/error toasts |
| [ ] | System config | `/config/system` + `/config/theme` at `/admin/config/*` | the full settings surface — site/app name, URLs, theme sidebar/header/color toggles, logo/background, commission + rate, payment handling fees, limits, queue health; validation error strings + save-success toast |
| [ ] | Announcements | `/admin/notice` | list + per-item inline errors, add/edit (公告标签/公告内容/图片URL + validation + "值不能为空"), validation; verify markdown/HTML preview |
| [ ] | Knowledge base | `/admin/knowledge` | article list + category/language filters + empty, add/edit (分类/语言/标题/value dialog), category create/sort (分类名称/分类语言/父ID), sort handles |
| [ ] | Tickets | `/ticket` | queue list + level/priority + status (开启/待回复/待答复/已关闭 + 从未在线 as never-online), detail with reply box ("输入内容回复工单…"), confirm/close dialogs |
| [ ] | Ticket detail | `/ticket/:ticket_id` | single ticket view + reply thread + close/reopen |
| [ ] | Tickets (pending / commission) | `/dashboard` links + `/order` + `/ticket` | "条工单等待处理" / "笔佣金等待确认" toast links, commission states (待确认/发放中/已发放/待答复/已关闭 … 7-day counts) |
| [ ] | Theme | `/admin/theme` | current theme, activation button, backend path / opcache error flows from ConfigController |
| [ ] | Settings | `/config/*` (`/config/system` + `/config/theme` via `/admin/config/*`) | the full settings surface — site/app name, URLs, theme sidebar/header/color toggles, logo/background, commission + rate, payment handling fees, limits, queue health; validation error strings + save-success toast |
| [ ] | Stats / Queue | `/queue` + `/dashboard` sub-dashboard + `/admin` health check | queue monitor (队列名称/队列监控/当前作业量/占用时间/指标/任务量/作业量/条/页), statistics queue, mail/Telegram/bulk-mail queues, traffic consumption queue, order queue, DNS table, failed-job counts (7日内报错数量) |
| [ ] | Server-rendered shell | `GET /<secure_path>` | HTML title, `<link>`/`<script>` tags with `?v=<version>` (cache-busted), `window.settings` JSON (title/sidebar/header/color/version/background/logo/secure_path) in view-source; env.example.js leftovers absent |
| [ ] | Cross-cutting: validation | every form that uses `app/Http/Requests/Admin/*` | trigger each "... cannot be empty" / "… is invalid" error, verify it reads English and that the submit is blocked |
| [ ] | Cross-cutting: confirmations | every destructive action | confirm dialog — title ("提醒"/"警告"/"删除用户"/"重置安全信息"), body (e.g. "确定要删除…的用户信息吗？", "确定要重置…的安全信息吗？"), buttons (确认/取消) |
| [ ] | Cross-cutting: toasts | async operations everywhere | success ("保存成功"/"删除成功"/"重置成功"/"发送成功"), failure ("发送失败"/"处理失败"), queued ("已加入队列执行"), running/empty states ("运行中"/"已开启"/"正常"/"封禁"/"从未在线"/"长期有效"/"无限"/"暂无数据") |
| [ ] | Cross-cutting: empty / loading | every paginated list | empty-table placeholder, loading skeleton, pagination component (条/页, 跳至, 上一页/下一页/向前/向后) |

## Wire/value follow-ups (visible but deliberately separate)

| Item | Location | Status |
|------|----------|--------|
| CSV export cell values (`不限制`/`金额`/`比例`/`流量`/`时长`/…) | `CouponController::generate`, `GiftcardController::export` (`用户`/`礼品卡` CSV downloads) | Left Chinese, not part of dashboard DOM — recorded as non-goals in the spec and as `_not_translated` in `strings/server-admin.en-US.json` |
| `长期有效` / `无订阅` in `UserController::exp*` | same export file cells, mirrored in the bundle labels `长期有效`/`无订阅` | Bundle side translated; PHP side still emits the same Chinese cell value to keep the CSV consistent |
| `" Fuzzy" / " Fuzzy,"` as a literal string (not the filter token) | `umi.js@d1ca:56` contains `" Fuzzy,"` as display text | Already English — unrelated to the `"模糊"` wire token, which is condition value, not display |
| `env.example.js` comments (`站点标题`/…) | `public/assets/admin/env.example.js` | Never loaded (no `<script>` tag, absent from `.gitignore`'s `/public/env.example.js` exclusion for deployed installs) — no change |

## Tool gate (must also pass)

```
node admin-i18n/check-shape.mjs          # 830 pairs, 0 suspicious today
node admin-i18n/extract.mjs --check      # inventory fresh against the bundles
node admin-i18n/guard.mjs                # 0 translatable residue in the live tree
node admin-i18n/server-messages.mjs --check # admin PHP has no untranslated display message
node admin-i18n/payments-concat.mjs --check # concatenation rewrites applied
docker run php:8.2-cli-alpine php -l app # no syntax errors
```
