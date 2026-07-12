# QQConnect

MediaWiki 扩展,通过 [QQ 互联(QQ Connect)](https://connect.qq.com/) OAuth2 实现 QQ 账号登录 MediaWiki。

适用于 **MediaWiki 1.43 及以上版本**,主要适配 [Citizen](https://www.mediawiki.org/wiki/Skin:Citizen) 皮肤,同时兼容 Vector、Timeless 等皮肤。

## 功能特性

- **QQ 账号登录**:用户可使用 QQ 账号登录 MediaWiki。
- **独立映射表**:扩展自行维护 `qqconnect_users` 表(MediaWiki 用户 ↔ QQ OpenID),不改变 MediaWiki 本身的账号系统结构。
- **首次登录灵活选择**:首次使用 QQ 登录的用户可选:
  - **创建新 MediaWiki 账号**:走 MediaWiki 标准注册流程,注册成功后自动绑定 QQ。
  - **绑定已有 MediaWiki 账号**:通过验证已有账号凭据(用户名+密码)完成绑定。
- **账号管理**:用户可在 `Special:QQConnect` 查看当前绑定的 QQ、更换绑定的 QQ、或解绑。入口同时出现在个人菜单和个人参数设置中。
- **尊重安全检查**:绝不绕过 OATHAuth(2FA)、AntiSpoof、ConfirmEdit(验证码)、TitleBlacklist 等扩展或内置逻辑的检查。详见下文[安全设计说明](#安全设计说明)。
- **占位测试模式**:QQ 互联平台要求网站先放置"QQ登录"按钮才能审核通过授予 appid。本扩展提供 `$wgQQConnectTestMode` 开关,开启时先显示按钮(供审核),但不承载真实登录功能。
- **管理员管理**:拥有 `qqconnect-manage` 权限的管理员可在 `Special:QQConnectAdmin` 查看所有用户的 QQ 绑定,并强制解绑。
- **强制绑定**:可选开启 `$wgQQConnectRequireBind`,要求用户必须绑定 QQ 才能编辑页面。

## 安装

### 1. 获取扩展

将本扩展目录放入 MediaWiki 的 `extensions/` 目录下,使路径为 `extensions/QQConnect/`。

### 2. 启用扩展

在 `LocalSettings.php` 末尾添加:

```php
wfLoadExtension( 'QQConnect' );
```

### 3. 运行数据库更新

运行 MediaWiki 的更新脚本以创建 `qqconnect_users` 表:

```bash
php maintenance/update.php
```

### 4. 配置 QQ 互联凭据

在 QQ 互联平台 [connect.qq.com](https://connect.qq.com/) 注册"网站应用",获取 APP ID 和 APP KEY。在 `LocalSettings.php` 中配置:

```php
wfLoadExtension( 'QQConnect' );

$wgQQConnectAppId = '你的APPID';
$wgQQConnectAppKey = '你的APPKEY';
$wgQQConnectTestMode = true;  // 先保持测试模式,供审核
```

### 5. 配置回调地址

在 QQ 互联管理中心的"回调地址"处,填写本扩展的回调地址。默认为:

```
https://你的网站域名/wiki/Special:QQConnectLogin
```

(若你的 `$wgArticlePath` 不是 `/wiki/$1`,请相应调整。也可通过 `$wgQQConnectRedirectUri` 显式指定。)

**注意**:QQ 互联要求回调地址的域名与注册应用时填写的网站域名一致,否则会报 `redirect uri is illegal(100010)` 错误。

## 配置项

所有配置均在 `LocalSettings.php` 中设置。详见 [docs/CONFIG.md](docs/CONFIG.md)。

| 配置项 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `$wgQQConnectAppId` | string | `''` | QQ 互联 APP ID |
| `$wgQQConnectAppKey` | string | `''` | QQ 互联 APP KEY(密钥,保密) |
| `$wgQQConnectRedirectUri` | string\|null | `null` | OAuth 回调地址,null 时自动生成 |
| `$wgQQConnectTestMode` | bool | `true` | 占位测试模式开关 |
| `$wgQQConnectRequireBind` | bool | `false` | 强制绑定 QQ 才能编辑 |
| `$wgQQConnectScopes` | string | `'get_user_info'` | 请求的 QQ 互联 API 权限 |

## 使用流程

### 审核阶段(上线前)

1. 按上文安装并配置扩展,`$wgQQConnectTestMode = true`(默认)。
2. 此时登录页和个人菜单会出现"QQ登录"按钮,但点击后显示测试提示页(不执行真实 OAuth)。
3. 在 QQ 互联平台提交应用审核。审核员会检查网站是否已放置 QQ 登录按钮。
4. 审核通过后,获取正式 APP ID 和 APP KEY,填入配置。

### 正式上线

1. 确认 `$wgQQConnectAppId` 和 `$wgQQConnectAppKey` 已正确填写。
2. 将 `$wgQQConnectTestMode = false;` 关闭测试模式。
3. 此时"QQ登录"按钮将执行真实 OAuth 流程,用户可正常使用 QQ 登录。

### 用户登录

1. 用户点击登录页的"QQ登录"按钮(或个人菜单的"QQ登录"链接)。
2. 跳转到 QQ 授权页面,用户授权后回调到本站。
3. 若该 QQ 已绑定 MediaWiki 账号:直接登录(若该账号启用了 OATHAuth 2FA,会要求输入 2FA 验证码)。
4. 若该 QQ 未绑定:显示选择页:
   - **创建新账号**:跳转到注册页(用户名预填 QQ 昵称),走标准注册流程,注册成功后自动绑定。
   - **绑定已有账号**:输入 MediaWiki 用户名和密码验证后绑定并登录。

### 账号管理

登录后访问 `Special:QQConnect`(或通过个人菜单的"QQ"链接、个人参数设置的"QQ互联"分区进入):
- 查看当前绑定的 QQ(昵称、OpenID、头像、绑定时间)。
- 点击"更换"重新走 QQ 授权流程,绑定新的 QQ(旧绑定自动解除)。
- 点击"解绑"解除当前 QQ 绑定。

### 管理员操作

拥有 `qqconnect-manage` 权限的用户可访问 `Special:QQConnectAdmin`:
- 查看所有用户的 QQ 绑定列表。
- 按用户名搜索。
- 强制解绑任意用户的 QQ(仅删除绑定关系,不影响 MediaWiki 账号本身)。

> **注意**:本扩展**不预定义任何用户组的权限授予**。`qqconnect-manage` 权限默认不授予任何组,站点管理员需在 `LocalSettings.php` 中手动分配(详见[权限配置](#权限))。

## 安全设计说明

本扩展严格遵守"不绕过 MediaWiki 安全检查"的原则:

1. **绝不自动创建账号**:MediaWiki 的自动建号(`autoCreateUser`)路径会绕过 ConfirmEdit 验证码和 AntiSpoof 冲突检查。本扩展**从不**对不存在的用户返回 `newPass`,因此不会触发自动建号。
2. **新账号走标准注册**:首次 QQ 登录创建新账号时,用户被引导到标准的 `Special:CreateAccount`,此流程运行所有 PreAuthenticationProvider 检查(验证码、AntiSpoof、TitleBlacklist)。绑定在 `LocalUserCreated` hook 中完成。
3. **绑定已有账号走 AuthManager 验证**:绑定已有账号时,凭据通过 `AuthManager::beginAuthentication` 验证,因此 OATHAuth 2FA 等二次验证会正常运行。
4. **已绑定用户登录不绕过 2FA**:已绑定 QQ 的用户登录时,本扩展返回 `newPass($username)`,AuthManager 会自动运行所有 secondary provider(包括 OATHAuth),2FA 不被绕过。
5. **CSRF 防护**:OAuth 流程使用 `state` 参数防 CSRF,state 存于加密 session 中,回调时严格校验。

## 皮肤兼容性

| 皮肤 | 兼容性 | 说明 |
|---|---|---|
| **Citizen** | ✅ 主要适配 | 个人菜单从标准 `data-user-menu` portlet 渲染,样式已优化 |
| **Vector**(2022) | ✅ 兼容 | 同上 |
| **Timeless** | ✅ 兼容 | 同上 |
| 其他基于 SkinTemplate 的皮肤 | ✅ 应当兼容 | 只要使用标准 portlet 渲染即可 |

QQ 登录按钮通过 `ButtonAuthenticationRequest` 注入登录表单,在所有皮肤中表现一致。

## 数据库表

扩展创建一张表 `qqconnect_users`:

| 列 | 类型 | 说明 |
|---|---|---|
| `qqc_user` | INT UNSIGNED | MediaWiki user_id(主键) |
| `qqc_openid` | VARCHAR(255) | QQ OpenID |
| `qqc_appid` | VARCHAR(255) | 绑定时的 APPID |
| `qqc_nickname` | VARCHAR(255) | QQ 昵称(展示用) |
| `qqc_avatar` | VARCHAR(512) | QQ 头像 URL |
| `qqc_bound_timestamp` | BINARY(14) | 绑定时间 |

约束:`qqc_user` 主键(一个 MediaWiki 用户最多绑一个 QQ);`(qqc_openid, qqc_appid)` 唯一索引(一个 QQ 最多绑一个 MediaWiki 用户)。

## 权限

本扩展注册了 `qqconnect-manage` 权限,但**不在 `extension.json` 中预定义任何用户组的权限授予**。该权限默认不授予任何组,需由站点管理员手动分配。

| 权限 | 默认授予 | 说明 |
|---|---|---|
| `qqconnect-manage` | 无(需手动配置) | 管理其他用户的 QQ 绑定(访问 Special:QQConnectAdmin) |

### 权限配置示例

在 `LocalSettings.php` 中(`wfLoadExtension( 'QQConnect' );` 之后)按需分配:

```php
// 授予 sysop(管理员)组管理权限
$wgGroupPermissions['sysop']['qqconnect-manage'] = true;

// 授予 bureaucrat(行政员)组管理权限
$wgGroupPermissions['bureaucrat']['qqconnect-manage'] = true;

// 或授予自定义用户组
$wgGroupPermissions['qqconnect-manager']['qqconnect-manage'] = true;
```

## 特殊页面

| 页面 | 权限 | 说明 |
|---|---|---|
| `Special:QQConnectLogin` | 所有人(含匿名) | QQ OAuth 回调与流程控制 |
| `Special:QQConnect` | 需登录 | 用户管理自己的 QQ 绑定 |
| `Special:QQConnectAdmin` | `qqconnect-manage` | 管理员管理所有绑定 |

## 依赖

- MediaWiki ≥ 1.43
- PHP ≥ 8.1
- PHP 扩展:`curl`、`json`、`mbstring`

## 常见问题

### Q: 测试模式下按钮点击后显示什么?
A: 显示一个说明页,告知用户 QQ 登录处于测试模式,管理员需配置 APP ID/KEY 并关闭测试模式才能正式启用。

### Q: 一个 QQ 能绑多个 MediaWiki 账号吗?
A: 不能。一个 QQ OpenID(对应一个 APPID)只能绑定一个 MediaWiki 账号。反之,一个 MediaWiki 账号也只能绑一个 QQ。

### Q: 更换绑定后,旧 QQ 还能登录原账号吗?
A: 不能。更换绑定会用新 QQ 覆盖旧绑定记录,旧 QQ 自动解除关联。

### Q: 启用 `$wgQQConnectRequireBind` 后,管理员也必须绑定才能编辑吗?
A: 不,拥有 `qqconnect-manage` 权限的用户豁免。

### Q: 回调地址报 `redirect uri is illegal(100010)` 怎么办?
A: 请确认 QQ 互联管理中心填写的回调地址域名与实际请求的 `redirect_uri` 域名完全一致。可用 `$wgQQConnectRedirectUri` 显式指定。

## 开发文档

- [配置参考](docs/CONFIG.md)
- [项目计划与进度](Plan.md)

## 许可证

GPL-2.0-or-later
