# QQConnect 配置参考

本文件详细说明 QQConnect 扩展的所有配置项。所有配置均在 `LocalSettings.php` 中设置,且需在 `wfLoadExtension( 'QQConnect' );` 之后。

---

## `$wgQQConnectAppId`

- **类型**:string
- **默认值**:`''`(空字符串)
- **说明**:QQ 互联平台分配的 APP ID(应用ID)。

获取方式:在 [connect.qq.com](https://connect.qq.com/) 注册"网站应用"后,在管理中心查看。

```php
$wgQQConnectAppId = '1234567890';
```

---

## `$wgQQConnectAppKey`

- **类型**:string
- **默认值**:`''`(空字符串)
- **说明**:QQ 互联平台分配的 APP KEY(应用密钥)。**此值必须保密**,不要提交到公开仓库。

```php
$wgQQConnectAppKey = 'abcdef0123456789abcdef0123456789';
```

---

## `$wgQQConnectRedirectUri`

- **类型**:string|null
- **默认值**:`null`(自动生成)
- **说明**:OAuth 回调地址。设为 `null` 时,扩展自动生成 `Special:QQConnectLogin` 的完整 URL。

若自动生成的地址与 QQ 互联平台注册的回调地址不一致(例如使用了短URL、反向代理、非标准 `$wgArticlePath`),请显式指定:

```php
$wgQQConnectRedirectUri = 'https://example.com/wiki/Special:QQConnectLogin';
```

**重要**:此地址的域名必须与 QQ 互联管理中心注册的网站域名一致,否则会报 `redirect uri is illegal(100010)`。

---

## `$wgQQConnectTestMode`

- **类型**:boolean
- **默认值**:`true`
- **说明**:占位测试模式开关。

**这是本扩展的核心特性之一**,用于应对 QQ 互联平台的审核要求:平台要求网站先放置"QQ登录"按钮,审核通过后才正式授予可用 appid。

| 值 | 行为 |
|---|---|
| `true`(默认) | 登录页和个人菜单显示"QQ登录"按钮,但点击后显示测试提示页,不执行真实 OAuth 流程。**此模式用于审核阶段**。 |
| `false` | 关闭测试模式,QQ登录按钮执行真实 OAuth 流程。**审核通过并配置好 APP ID/KEY 后设为此值**。 |

```php
// 审核阶段(默认)
$wgQQConnectTestMode = true;

// 正式上线
$wgQQConnectTestMode = false;
```

**注意**:即使 `TestMode = true`,若未配置 APP ID/KEY,扩展也会显示测试提示页(而非报错),确保审核阶段按钮始终可用。

---

## `$wgQQConnectRequireBind`

- **类型**:boolean
- **默认值**:`false`
- **说明**:开启后,未绑定 QQ 的用户无法执行编辑类操作(编辑、创建、移动、删除、上传等)。

此功能用于需要强制实名(QQ 绑定)的站点。登录本身不受限制,仅限制编辑类操作。

**豁免规则**:
- 匿名用户不限制(匿名用户本就受其他权限限制)。
- 拥有 `qqconnect-manage` 权限的用户(bureaucrat、sysop)豁免。

```php
$wgQQConnectRequireBind = true;
```

未绑定用户尝试编辑时,会看到提示信息,引导其前往 `Special:QQConnect` 绑定 QQ。

---

## `$wgQQConnectScopes`

- **类型**:string
- **默认值**:`'get_user_info'`
- **说明**:向 QQ 互联请求的 API 权限范围,多个权限用逗号分隔。

`get_user_info` 是登录所需的最小权限(获取昵称、头像等)。如需调用其他 QQ 互联 API,可在此添加(需在 QQ 互联平台申请相应权限):

```php
$wgQQConnectScopes = 'get_user_info,list_album,upload_pic';
```

---

## 完整配置示例

```php
wfLoadExtension( 'QQConnect' );

// QQ 互联凭据
$wgQQConnectAppId = '1234567890';
$wgQQConnectAppKey = 'your_app_key_here';

// 回调地址(可选,不设则自动生成)
// $wgQQConnectRedirectUri = 'https://example.com/wiki/Special:QQConnectLogin';

// 测试模式:审核期间保持 true,审核通过后改为 false
$wgQQConnectTestMode = false;

// 可选:强制绑定才能编辑
// $wgQQConnectRequireBind = true;

// 可选:API 权限范围(默认即可)
// $wgQQConnectScopes = 'get_user_info';
```

---

## 权限配置

扩展注册了 `qqconnect-manage` 权限,默认授予 bureaucrat 和 sysop 组。如需自定义:

```php
// 额外授予某用户组管理权限
$wgGroupPermissions['moderator']['qqconnect-manage'] = true;

// 撤销 sysop 的管理权限
$wgGroupPermissions['sysop']['qqconnect-manage'] = false;
```

---

## 白名单(可选)

若你的站点设置了 `$wgWhitelistRead`(限制匿名用户可读页面),需将 QQ 登录页面加入白名单,否则匿名用户无法访问:

```php
$wgWhitelistRead[] = 'Special:QQConnectLogin';
```

---

## 排错

### `redirect uri is illegal(100010)`
回调地址域名与 QQ 互联注册的域名不一致。检查 `$wgQQConnectRedirectUri` 和 QQ 互联管理中心的回调地址设置。

### 按钮点击后显示"测试模式"提示页
`$wgQQConnectTestMode` 为 `true`。审核通过后设为 `false`。

### 按钮点击后显示"未配置"错误
`$wgQQConnectAppId` 或 `$wgQQConnectAppKey` 为空。填写正确的凭据。

### OAuth 流程报错(token/openid/userinfo)
查看 MediaWiki 日志(logs 目录,channel `qqconnect`),确认 QQ 互联 API 返回的具体错误。常见原因:网络问题、APP KEY 错误、权限不足。

### 用户登录后 2FA 未触发
确认 OATHAuth 扩展已正确安装且该用户启用了 2FA。本扩展对已绑定用户返回 `newPass`,OATHAuth 作为 secondary provider 会自动运行,无需额外配置。
