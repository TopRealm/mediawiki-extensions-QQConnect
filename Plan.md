# QQConnect 扩展 — 项目计划与进度跟踪

> **本文件用途**:完整记录 MediaWiki QQConnect 扩展的设计决策、技术方案、文件清单与实现进度。无论何时,任何 AI 或人类开发者接手此项目,只需阅读本文件即可理解全貌并继续开发,流程连贯不中断。

## 一、项目概述
为 MediaWiki 1.43+ 开发名为 `QQConnect` 的扩展,通过 QQ 互联(QQ Connect)OAuth2 实现 QQ 登录。

**核心特性**:
1. 独立维护 `qqconnect_users` 映射表,不改变 MediaWiki 账号系统结构。
2. 首次 QQ 登录:用户可选创建新 MediaWiki 账号(走标准注册流程,自动绑定)或绑定已有账号(验证凭据后绑定)。
3. 特殊页面管理:查看当前绑定、更换绑定、解绑。入口在个人参数设置和个人菜单。
4. 尊重 OATHAuth(2FA)、AntiSpoof、ConfirmEdit(验证码)、TitleBlacklist 等检查,绝不绕过。
5. 占位测试模式(`$wgQQConnectTestMode`):先显示 QQ 登录按钮供平台审核,不承载真实功能。
6. 主要适配 Citizen 皮肤,兼容 Vector、Timeless。

## 二、已确认的设计决策
| 决策点 | 选择 |
|---|---|
| 首次登录新账号用户名 | 预填 QQ 昵称(清理非法字符),用户可编辑 |
| 更换绑定 | 直接发起新 OAuth 流程覆盖旧绑定 |
| 测试模式按钮行为 | 显示测试提示页,不执行真实 OAuth |
| 附加功能 | 管理员强制解绑 + `$wgQQConnectRequireBind` 强制绑定才能编辑 |

## 三、关键技术方案(均经源码验证)

### 3.1 认证流程设计(核心:不绕过安全检查)
MediaWiki 有两条建号路径:
- **Flow A 标准建号**(`beginAccountCreation`/Special:CreateAccount):运行所有 PreAuthenticationProvider 的 `testForAccountCreation` → ConfirmEdit 验证码、AntiSpoof、TitleBlacklist 全部生效。
- **Flow B 自动建号**(`autoCreateUser`):当 primary provider 对不存在用户返回 `newPass` 时触发 → **绕过** ConfirmEdit 和 AntiSpoof。

**本扩展方案**:
- **已绑定 QQ 登录**:`continuePrimaryAuthentication` 返回 `newPass($existingUsername)` → OATHAuth 二次验证(secondary provider)自动运行 → 2FA 不被绕过。
- **未绑定 QQ 登录**:**绝不**返回 `newPass`(避免 Flow B)。改为存 openid+用户信息到 session,显示选择页:
  - **创建新账号** → 预填用户名重定向到 `Special:CreateAccount`,走完整 Flow A → `LocalUserCreated` hook 检测 session 完成绑定。
  - **绑定已有账号** → 显示用户名+密码表单,提交通过 AuthManager 验证(触发 OATHAuth 等)→ 验证成功写入绑定 → 登录。

### 3.2 QQ Connect OAuth2 流程(4 个 GET 端点)
1. 授权:`https://graph.qq.com/oauth2.0/authorize?response_type=code&client_id=APPID&redirect_uri=...&state=...&scope=get_user_info`
2. 换 token:`https://graph.qq.com/oauth2.0/token` → **响应是 urlencoded**,用 `parse_str` 解析
3. 取 openid:`https://graph.qq.com/oauth2.0/me?access_token=...&fmt=json` → 用 `fmt=json` 避免 JSONP
4. 取用户信息:`https://graph.qq.com/user/get_user_info?access_token=...&oauth_consumer_key=APPID&openid=...` → 参数名是 `oauth_consumer_key`

state 存 session 防 CSRF。openid 作为稳定标识(非昵称)。

### 3.3 MediaWiki 1.43 关键 API(已验证)
- `PersonalUrls` hook 已移除 → 用 `SkinTemplateNavigation::Universal`
- 认证提供者经 `AuthManagerAutoConfig.primaryauth` 注册(ObjectFactory spec)
- 登录按钮用 `ButtonAuthenticationRequest` + `AuthChangeFormFields`(定位 weight)
- 数据库用 `schema/tables.json` 抽象 schema + `LoadExtensionSchemaUpdates`(handler 不能用 DI)
- Session 状态用 `AuthManager::setAuthenticationSessionData`(加密 session secret)
- Citizen/Vector/Timeless 都从标准 `data-user-menu` portlet 渲染,统一注入点

## 四、文件清单与状态

| 文件 | 状态 | 说明 |
|---|---|---|
| `.gitignore` | ✅ | 忽略 vendor/、IDE、.zcode |
| `extension.json` | ✅ | manifest v2,注册全部 |
| `i18n/en.json` | ✅ | 英文消息 |
| `i18n/zh-hans.json` | ✅ | 简体中文消息 |
| `i18n/qqq.json` | ✅ | 消息文档 |
| `schema/tables.json` | ✅ | qqconnect_users 抽象 schema |
| `schema/mysql/tables-generated.sql` | ✅ | MySQL 建表 |
| `schema/postgres/tables-generated.sql` | ✅ | PostgreSQL 建表 |
| `schema/sqlite/tables-generated.sql` | ✅ | SQLite 建表 |
| `includes/SchemaHooks.php` | ✅ | LoadExtensionSchemaUpdates |
| `includes/QQConnectConfig.php` | ✅ | 配置封装(QQConnectConfig) |
| `includes/ServiceWiring.php` | ✅ | DI 服务装配 |
| `includes/QQStore.php` | ✅ | qqconnect_users 表 CRUD |
| `includes/QQClient.php` | ✅ | QQ OAuth2 HTTP 客户端 |
| `includes/QQConnectException.php` | ✅ | 客户端异常 |
| `includes/Util/UsernameCleaner.php` | ✅ | QQ 昵称→合法用户名 |
| `includes/Auth/QQLoginAuthenticationRequest.php` | ✅ | 登录按钮 Request |
| `includes/Auth/QQContinueAuthenticationRequest.php` | ✅ | 回调继续 Request |
| `includes/Auth/QQPrimaryAuthenticationProvider.php` | ✅ | 核心认证提供者 |
| `includes/Special/SpecialQQConnectLogin.php` | ✅ | OAuth 回调+选择页 |
| `includes/Special/SpecialQQConnect.php` | ✅ | 用户管理页 |
| `includes/Special/SpecialQQConnectAdmin.php` | ✅ | 管理员页 |
| `includes/HookHandler.php` | ✅ | 所有 hooks |
| `resources/qqconnect.css` | ✅ | 样式 |
| `resources/qqconnect.js` | ✅ | 前端辅助 |
| `README.md` | ✅ | 主文档 |
| `docs/CONFIG.md` | ✅ | 配置参考 |
| `Plan.md` | ✅ | 本文件 |
| `LICENSE` | ✅ | GPL-2.0-or-later |

## 五、实现进度

### 阶段0:项目骨架 ✅
- [x] 0.1 初始化 git 仓库,创建 `.gitignore`
- [x] 0.2 创建 `extension.json`
- [x] 0.3 创建 i18n 消息文件

### 阶段1:数据库层 ✅
- [x] 1.1 `schema/tables.json`
- [x] 1.2 三平台 `tables-generated.sql`
- [x] 1.3 `includes/SchemaHooks.php`
- [x] 1.4 `includes/QQStore.php`

### 阶段2:QQ API 客户端 ✅
- [x] 2.1 `includes/QQClient.php`(含异常类)
- [x] 2.2 `includes/Util/UsernameCleaner.php`

### 阶段3:认证提供者 ✅
- [x] 3.1 `QQLoginAuthenticationRequest`
- [x] 3.2 `QQContinueAuthenticationRequest`
- [x] 3.3 `QQPrimaryAuthenticationProvider`

### 阶段4:OAuth 回调特殊页面 ✅
- [x] 4.1 `SpecialQQConnectLogin`(回调、测试模式、选择页、绑定表单)

### 阶段5:用户管理页 ✅
- [x] 5.1 `SpecialQQConnect`(查看/更换/解绑)
- [x] 5.2 `SpecialQQConnectAdmin`(管理员强制解绑)

### 阶段6:Hooks 集成 ✅
- [x] 6.1 `AuthChangeFormFields`(按钮定位)
- [x] 6.2 `SkinTemplateNavigation::Universal`(个人菜单入口)
- [x] 6.3 `GetPreferences`(参数设置分区)
- [x] 6.4 `LocalUserCreated`(新账号绑定)
- [x] 6.5 `getUserPermissionsErrors`(强制绑定)

### 阶段7:前端资源 ✅
- [x] 7.1 `resources/qqconnect.css`
- [x] 7.2 `resources/qqconnect.js`
- [x] 7.3 extension.json ResourceModules 注册

### 阶段8:文档 ✅
- [x] 8.1 `README.md`
- [x] 8.2 `docs/CONFIG.md`
- [x] 8.3 `Plan.md`(本文件)

### 阶段9:验证 ✅
- [x] 9.1 PHP 语法检查(`php -l`)—— 全部 14 个 PHP 文件通过
- [x] 9.2 extension.json JSON 校验 —— 全部 5 个 JSON 文件有效
- [x] 9.3 代码审查修复:
  - 修复 `HookHandler.php` 和 `QQPrimaryAuthenticationProvider.php` 中 `use User;` 与 `use MediaWiki\User\User;` 的重复/冲突
  - 修复 `ServiceWiring.php` 中 `getConfigRepository()->get('QQConnect')`(会抛异常)→ 改用 `getMainConfig()`(PluggableAuth 模式)
  - 修复 `QQStore::rebind()` 中 `startAtomic` 默认 `ATOMIC_NOT_CANCELABLE` 导致 catch 无法回滚 → 改为 `IDatabase::ATOMIC_CANCELABLE`
  - 修复 `SpecialQQConnectLogin::resumeLoginFlow()` 重定向到裸 `Special:Userlogin`(无法触发 `continueAuthentication`)→ 改为重定向到 AuthManager 提供的 `returnToUrl`
  - 修复 `SpecialQQConnectLogin::startFlow()` 直接发起 OAuth 绕过 AuthManager → 非登录流程的匿名用户重定向到登录表单
  - 简化 `ResourceModules` 配置(FileModule 是默认),添加 `remoteExtPath`
- [x] 9.4 git 提交

## 六、后续可改进项(未实现)
- [ ] 单元测试(PHPUnit)
- [ ] QQ OpenID unionid 支持(跨应用统一身份)
- [ ] 头像同步到 MediaWiki 用户头像
- [ ] 登录时记录 QQ 昵称为 realname(可选)

## 七、参考来源(均已验证)
- **MediaWiki core REL1_43**(gerrit.wikimedia.org):
  - `includes/auth/AuthManager.php`、`AbstractPrimaryAuthenticationProvider.php`、`ButtonAuthenticationRequest.php`、`AuthenticationResponse.php`
  - `includes/skins/SkinTemplate.php`(PersonalUrls 移除,buildPersonalUrls)
  - `includes/installer/DatabaseUpdater.php`、`docs/extension.schema.v2.json`
  - `docs/abstract-schema*.json`(tables.json 规范)
- **PluggableAuth REL1_43**:`PrimaryAuthenticationProvider.php`、`BeginAuthenticationRequest.php`(流程参考)
- **OpenIDConnect REL1_43**:`OpenIDConnectStore.php`、`SchemaHooks.php`(Store 与 schema 参考)
- **OATHAuth/AntiSpoof/ConfirmEdit/TitleBlacklist REL1_43**:`testUserForCreation`/`testForAccountCreation` 行为(确认自动建号绕过验证码/spoof)
- **Citizen skin main 分支**:`UserMenu.mustache`(从 data-user-menu portlet 渲染)
- **QQ 互联 WIKI**(wiki.connect.qq.com):4 个 OAuth2 端点、参数、响应格式、审核规范、视觉素材

## 八、接手说明
若要继续开发:
1. 阅读本文件了解全貌。
2. 阅读各文件头部注释了解职责。
3. 核心逻辑在 `QQPrimaryAuthenticationProvider.php` 和 `SpecialQQConnectLogin.php`,务必理解"不绕过安全检查"的设计(3.1 节)。
4. 修改后运行阶段9的验证步骤。
5. 更新本文件的进度勾选。
