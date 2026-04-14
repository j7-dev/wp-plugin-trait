# wp-plugin-trait

WordPress plugin 開發用的 PHP traits library。以 Composer 套件形式被其他 plugin 引用，提供 plugin 啟動、依賴套件檢查、license check、自動更新、template loading 等通用基礎建設。

> 同時作為 Claude Code 的專案指引文件，詳細開發注意事項請見 [CLAUDE.md](CLAUDE.md)。

---

## 專案性質

這是一個 **Composer library**（不是 WordPress plugin 本身），提供 WordPress plugin 開發用的 PHP traits。被其他 plugin 以 `composer require j7-dev/wp-plugin-trait` 方式引用。

- Composer package: `j7-dev/wp-plugin-trait`
- PSR-4 autoload: `J7\WpUtils\Traits\` → [src/traits/](src/traits/)
- 授權: GPL-3.0-or-later
- PHP 版本下限: 8.0（見 [phpcs.xml:73](phpcs.xml#L73)）

## 安裝

```bash
composer require j7-dev/wp-plugin-trait
```

## 常用指令

```bash
# 安裝依賴
composer install

# Lint（WordPress Coding Standards）
composer lint              # 等同 vendor/bin/phpcs
vendor/bin/phpcbf           # 自動修復可修的 phpcs 問題

# 靜態分析（PHPStan level 9）
vendor/bin/phpstan analyse
```

> 專案**沒有單元測試框架**（composer.json 裡沒有 phpunit，phpcs.xml 也把 `tests/*` 排除）。

## Lint 規則重點

[phpcs.xml](phpcs.xml) 套用 `WordPress-Core` + `WordPress-Docs` + `WordPress-Extra` + `WordPress`，並：

- **強制 short array syntax**（`[]`），長語法會被擋
- **強制類別為 `final` 或 `abstract`**（`Universal.Classes.RequireFinalClass`）
- **強制 trait 內的 method 都要 `final`**（`Universal.FunctionDeclarations.RequireFinalMethodsInTraits`）— 這條在寫新 trait 方法時非常容易忘
- Tab 縮排、4 寬度、exact（見 [phpcs.xml:65-71](phpcs.xml#L65-L71)）
- PHPCompatibility 目標 `8.0-`

[phpstan.neon](phpstan.neon) 是 level 9，僅掃描 `/src`，已經 bootstrap 了 WordPress 與 WooCommerce stubs。

## 架構全貌

### [src/traits/SingletonTrait.php](src/traits/SingletonTrait.php)

極輕量的 singleton 模式：一個 `static $instance` + `instance(...$args)` 方法。使用方式是 `use SingletonTrait;`。注意：`new self(...$args)` 固定用自身類別，子類別不會被正確處理。

### [src/traits/PluginTrait.php](src/traits/PluginTrait.php)

**整個 library 的核心**。這是一個「WP Plugin 入口樣板」的 trait，使用方是「被 composer require 進去的 plugin 主類」，用法類似：

```php
final class Bootstrap {
    use \J7\WpUtils\Traits\PluginTrait;
    public function __construct() {
        $this->init([
            'app_name'    => 'My App',
            'github_repo' => 'https://github.com/xxx/xxx',
            'callback'    => [ $this, 'run' ],
        ]);
    }
}
```

`init()` 之後這個 trait 會一口氣做完：

1. **常數設定** (`set_const`)：從 `app_name` 推出 `$kebab`（"my-app"）與 `$snake`（"my_app"），用 `ReflectionClass` 抓呼叫者所在檔案作為 `$plugin_entry_path`，再從 `get_plugin_data()` 讀 Version。
2. **註冊 activation / deactivation hook**（空殼，由使用者複寫）。
3. **依賴套件檢查**：透過 `\J7_Required_Plugins`（來自 [j7-dev/tgm-plugin-activation-forked](https://github.com/j7-dev/tgm-plugin-activation-forked)）。`register_required_plugins()` 內部用 `\j7rp()` 全域函式註冊。
4. **License Check（LC）整合**：可選整合 `\J7\Powerhouse\LC`。若 class 存在且 `need_lc` 為 true，會把子選單掛到 `powerhouse` 母選單底下，未授權時導向 Powerhouse LC 頁面。
5. **Allowed domains 白名單**：`get_allowed_domains()` 會打 `https://cloud.luke.cafe/wp-json/power-partner-server/allow_domains` 取得免 LC 的網域清單，cache 14 天；失敗時 fallback 到 hard-coded 清單並**照樣 cache**（避免重複打爆 API）。
6. **Plugin Update Checker**：讀 plugin 目錄下的 `.puc_pat` 檔案（base64 encoded GitHub PAT），交給 `YahnisElsts\PluginUpdateChecker` 對 GitHub Release 做自動更新，branch 固定為 `master`。
7. **i18n**：`load_textdomain()` 用 **kebab-case**（不是 snake！）作為 textdomain，路徑為 plugin 根目錄下的 `languages/`。這是為了跟 plugin 主檔 `Text Domain:` header 對齊，**不要改回 snake，會讓所有 `__()` 呼叫靜默失效**。
8. **Template loader**：`load_template()` / `safe_load_template()` 從 `$dir . $template_path . '/templates/components/{name}.php'` 或 `.../pages/{name}.php` 載入檔案。判定 page 的條件是檔名以 `$template_page_names` 中任一項開頭（預設 `['404']`）。
9. **Module script loader**：`add_module_handle()` 可註冊要加上 `type="module"` 的 script handle，透過 `script_loader_tag` filter 改寫輸出。

### 對外依賴關係

```
此 library
 ├── j7-dev/tgm-plugin-activation-forked  # 提供 \j7rp() 與 \J7_Required_Plugins
 ├── yahnis-elsts/plugin-update-checker   # GitHub release 自動更新
 ├── （可選）\J7\Powerhouse\LC            # License Check，不存在時自動停用
 └── （可選）\J7\Powerhouse\Bootstrap     # LC menu redirect 目標
```

`\J7\Powerhouse\*` 是 soft dependency — 程式碼用 `class_exists()` 判斷，不存在時自動略過相關流程。

## 開發注意事項

1. **所有新增的 public / protected 方法都必須加 `final`**，否則 phpcs 直接擋。
2. **每個 trait 檔案開頭都要有 `if (trait_exists('XxxTrait')) { return; }` 防護**，見既有兩個檔案。原因：此 library 會被多個 plugin 同時 composer require，run-time 可能重複載入。
3. 使用 `self::$var` 時要記得這是 **trait 裡的 late static binding**，使用方 class 會共享 trait 內宣告的 `public static` 屬性 — 多個 plugin 各自 `use` 時會有各自的 static state。
4. 對 WordPress 全域函式使用**加 backslash 前綴**（如 `\add_action`、`\get_option`），這是既有風格，phpstan stubs 會接住。
5. 版本在 [composer.json](composer.json)；發 release 時要同步更新 — 此 library 自身也是透過 plugin-update-checker 派送給下游 plugin 的。

## 授權

GPL-3.0-or-later © [JerryLiu](https://github.com/j7-dev)
