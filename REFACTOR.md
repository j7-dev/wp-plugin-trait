# wp-plugin-trait 改善計畫

> 來源：wordpress-reviewer agent 對 `src/traits/` 的完整審查
> 原則：**所有修改必須向後相容**，不得刪除 public method、改變既有 signature、改變預期回傳值
> 統計：Critical 4 / Major 10 / Minor 13

---

## 設計決策（不要動）

以下兩項是專案明示的設計意圖，審查時已排除，**修改時請保留**：

1. `load_textdomain()` 使用 **kebab-case** 作為 textdomain（commit `db355ff`），對齊 plugin 主檔 `Text Domain:` header
2. `get_allowed_domains()` 即使 API 失敗也 cache 14 天（commit `a9aa865`），避免重複打爆上游 API

---

## Phase 1：Critical 安全修補（優先處理）

### C1. `get_allowed_domains()` 強化外部 API 回應驗證 + host 精確比對

**檔案**：`src/traits/PluginTrait.php:454-493`, `411-428`

**問題**：
- `wp_remote_get` 回應未驗證每個元素是合法 domain 字串
- 若上游 API 回 `[""]` 或 `["."]`，`strpos($site_url, $domain) !== false` 會對所有網址回 true（`strpos("https://x", "")` 回 `0`）→ **全站 LC bypass**
- `strpos` 是子字串匹配，`"cafe"` 會誤中 `https://hackercafe.evil.com`

**修法**（非 breaking，全在 private method 內）：

```php
// get_allowed_domains() 解析後加清洗
$allowed_domains = array_values(array_filter(
    array_map(
        static fn($d) => is_string($d) ? trim($d) : '',
        $domain_list
    ),
    static fn($d) => $d !== '' && preg_match('/^[a-z0-9.\-]+$/i', $d) === 1
));

if (!$allowed_domains) {
    throw new \Exception('domain_list sanitized to empty');
}
```

```php
// set_lc() 內的比對改成 host 級別
$site_host = \wp_parse_url(\site_url(), PHP_URL_HOST) ?: '';
foreach ($allowed_domains as $domain) {
    if ($domain === '') {
        continue;
    }
    if ($site_host === $domain || str_ends_with($site_host, '.' . $domain)) {
        self::$need_lc = false;
        break;
    }
}
```

**Breaking**：否

---

### C2. `set_puc_pat()` 與 `plugin_update_checker()` 防呆與錯誤可見性

**檔案**：`src/traits/PluginTrait.php:596-630`

**問題**：
- `file_get_contents` 沒檢查回傳 `false`，PHP 8.1+ 會丟 deprecation
- `base64_decode` 沒用 strict mode
- 註解寫「.env file」但讀的是 `.puc_pat` → 誤導
- `plugin_update_checker()` 的 `catch (\Throwable $th) {}` 完全吞錯誤，debug 看不到

**修法**：

```php
final public static function set_puc_pat(): void {
    $puc_pat_file = self::$dir . '/.puc_pat';
    if ( ! \file_exists( $puc_pat_file ) || ! \is_readable( $puc_pat_file ) ) {
        return;
    }
    $contents = \file_get_contents( $puc_pat_file );
    if ( false === $contents ) {
        return;
    }
    $decoded = \base64_decode( \trim( $contents ), true ); // strict mode
    if ( false === $decoded || '' === $decoded ) {
        return;
    }
    self::$puc_pat = $decoded;
}

final public function plugin_update_checker(): void {
    try {
        // ... 既有邏輯
    } catch ( \Throwable $th ) {
        if ( \defined( 'WP_DEBUG' ) && \WP_DEBUG ) {
            \error_log( '[' . self::$kebab . '] plugin_update_checker 失敗：' . $th->getMessage() );
        }
    }
}
```

**Breaking 警示**：加 `final` 嚴格說是 breaking（若下游 override）。
**前置動作**：先掃過下游 repo 確認沒人 override 這兩個 method 再加 final。
**保守做法**：只修內容、暫不加 final，CHANGELOG 註記下個 minor 版本才加。

---

### C3. `load_template()` 移除 `' '` 哨兵設計

**檔案**：`src/traits/PluginTrait.php:172-179`, `193-236`

**問題**：用單一空白字元當「找不到」的 sentinel 太脆弱，若模板輸出剛好是 `' '` 會被誤判。

**修法**（保持 public method signature 不變）：

```php
final public static function load_template(
    string $name,
    mixed $args = null,
    ?bool $output = true,
    ?bool $load_once = false
): ?string {
    $template_file = self::resolve_template_file($name);
    if (null === $template_file) {
        throw new \Exception("模板文件 {$name} 不存在");
    }
    if ($output) {
        \load_template($template_file, (bool) $load_once, $args);
        return null;
    }
    \ob_start();
    \load_template($template_file, (bool) $load_once, $args);
    return (string) \ob_get_clean();
}

private static function resolve_template_file(string $name): ?string {
    $is_page = false;
    foreach (self::$template_page_names as $page_name) {
        if (\str_starts_with($name, $page_name)) {
            $is_page = true;
            break;
        }
    }
    $folder = $is_page ? 'pages' : 'components';
    $base   = self::$dir . self::$template_path . "/templates/{$folder}/{$name}";

    if (\file_exists("{$base}.php")) {
        return "{$base}.php";
    }
    if (\file_exists("{$base}/index.php")) {
        return "{$base}/index.php";
    }
    return null;
}
```

`safe_load_template()` 內部也改用 `resolve_template_file()`，public signature 完全不動。

**Breaking**：否

---

### C4. `safe_load_template()` 路徑遍歷防護

**檔案**：`src/traits/PluginTrait.php:192-236`

**問題**：`$name` 直接串接到 path，沒擋 `..` 或絕對路徑。雖然下游通常不會把 user input 丟進來，但 library 該 defensive。

**修法**：

```php
final public static function safe_load_template(
    string $name,
    mixed $args = null,
    ?bool $echo = true,
    ?bool $load_once = false,
): string|false|null {

    // 路徑遍歷防護
    if (\str_contains($name, '..')
        || \str_starts_with($name, '/')
        || \preg_match('#^[a-z]:#i', $name) === 1
    ) {
        return ' '; // 維持原 sentinel 行為（在 C3 修完前）
    }
    // ... 既有邏輯
}
```

**注意**：C3 與 C4 應一起做，C3 完成後此處的 `return ' '` 改為對應的新 sentinel 機制。

**Breaking**：否

---

## Phase 2：Major 重要修補

### M6. `register_required_plugins()` textdomain 全寫死導致 i18n 失效（先做）

**檔案**：`src/traits/PluginTrait.php:511-585`

**問題**：所有 `\__(..., 'wp_react_plugin')` 寫死了一個 snake_case textdomain，但下游 plugin 實際 textdomain 是 `self::$kebab`，**翻譯全部 fallback 回原文**。

**修法**：把所有 `'wp_react_plugin'` 替換為 `self::$kebab`。`_n_noop()` 第三個參數雖建議字面量，但傳變數技術上可行（WP 翻譯時會用 array 內記錄的 domain）。

```php
// before
'dismiss_msg' => \__('這個訊息將...', 'wp_react_plugin'),

// after
'dismiss_msg' => \__('這個訊息將...', self::$kebab),
```

**Breaking**：否（純 bug fix）

---

### M3. `plugin_update_checker()` 延後到 admin/cron 才啟動

**檔案**：`src/traits/PluginTrait.php:331-334`

**問題**：每次 request（含前端）都跑 reflection + file I/O + PUC 物件建立，不必要。

**修法**：

```php
$this->register_required_plugins();
\add_action('init', function () {
    if (\is_admin() || ( \defined('DOING_CRON') && \DOING_CRON )) {
        $this->set_puc_pat();
        $this->plugin_update_checker();
    }
});
```

**注意**：若下游有讀 `SomeClass::$puc_pat`，會因延後初始化讀到 null。**保險做法**：`set_puc_pat()` 維持同步執行，只延後 `plugin_update_checker()`。

**Breaking**：否（API 不變，hook 時機延後對前端有利）

---

### M1. `SingletonTrait` 改用 `new static` + per-class array

**檔案**：`src/traits/SingletonTrait.php:30-36`

**問題**：`new self()` 對子類別繼承行為錯亂，`self::$instance` 父子類共用同一 slot。

**修法**：

```php
private static array $instances = [];

public static function instance(...$args) {
    $class = static::class;
    if ( ! isset( self::$instances[ $class ] ) ) {
        self::$instances[ $class ] = new static(...$args);
    }
    return self::$instances[ $class ];
}
```

**Breaking**：`$instance` → `$instances` 屬性是 `private`，外部不可存取，**不算 breaking**。`new self → new static` 對既有使用方式（`MyClass::instance()`）完全相容。

**注意**：暫不加 `final`（同 C2 的考量）。

---

### M7. `add_type_attribute()` 加 attribute escape + 白名單

**檔案**：`src/traits/PluginTrait.php:267-278`

**修法**：

```php
final public static function add_type_attribute( $tag, $handle, $src ) {
    if ( ! isset( self::$module_handles[ $handle ] ) ) {
        return $tag;
    }
    $strategy = (string) self::$module_handles[ $handle ];
    $allowed  = [ 'async', 'defer', '' ];
    if ( ! \in_array( $strategy, $allowed, true ) ) {
        $strategy = '';
    }
    return \sprintf(
        '<script type="module" src="%1$s"%2$s></script>',
        \esc_url( $src ),
        '' !== $strategy ? ' ' . \esc_attr( $strategy ) : ''
    );
}
```

**Breaking**：保留無 type hint 簽章以最大相容。

---

### M4. `set_lc()` 後門字串抽常數明示

**檔案**：`src/traits/PluginTrait.php:411-418`

**問題**：`'ZmFsc2'`（base64 of 'fals'）是 hard-coded 後門，library 把它佈到所有下游。

**修法**（短期）：抽常數加註解，讓維護者知道這是刻意設計。長期建議改用 `wp_options` 或 PHP constant 控制。

```php
private const LC_DISABLE_TOKEN = 'ZmFsc2';
private const LC_SKIP_TOKEN    = 'c2tpcA';

final public function set_lc(array $args): void {
    if (isset($args['lc'])) {
        $is_disable = in_array($args['lc'], [self::LC_DISABLE_TOKEN, false], true);
        $is_skip    = in_array($args['lc'], [self::LC_SKIP_TOKEN, 'skip'], true);
        self::$need_lc = $is_skip ? (string) $args['lc'] : ! $is_disable;
    } else {
        self::$need_lc = \class_exists('\J7\Powerhouse\LC');
    }
    // ...
}
```

**Breaking**：否（支援的字串值不變）

---

### M8. `get_allowed_domains()` cache key 加 namespace

**檔案**：`src/traits/PluginTrait.php:455, 491`

**修法**：

```php
private const ALLOWED_DOMAINS_TRANSIENT = 'j7wpt_allowed_domains';

$allowed_domains = \get_transient(self::ALLOWED_DOMAINS_TRANSIENT);
// ...
\set_transient(self::ALLOWED_DOMAINS_TRANSIENT, $allowed_domains, 14 * \DAY_IN_SECONDS);
```

**Breaking**：否（transient key 是私有實作細節，舊 transient 自然過期）

---

### M9. `set_lc()` 重複 hook 註冊防護

**檔案**：`src/traits/PluginTrait.php:430`

**修法**：

```php
if ( ! \has_action('admin_menu', [ __CLASS__, 'add_lc_menu' ]) ) {
    \add_action('admin_menu', [ __CLASS__, 'add_lc_menu' ], 20);
}
```

**Breaking**：否

---

### M10. `set_const()` 處理 `getFileName()` 回 false 的情況

**檔案**：`src/traits/PluginTrait.php:360-361`

**修法**：

```php
$reflector  = new \ReflectionClass( static::class );
$entry_path = $reflector->getFileName();
if ( false === $entry_path ) {
    throw new \RuntimeException(
        sprintf( 'Cannot resolve plugin entry path for class %s', static::class )
    );
}
self::$plugin_entry_path = $entry_path;
```

**Breaking**：否（合法情境行為一致，異常情境提早 fail）

---

### M2. `SingletonTrait::$instance` PHPDoc 補 nullable

**檔案**：`src/traits/SingletonTrait.php:16-21`

**修法**：`@var self` → `@var self|null`

**Breaking**：否

---

### M5. `init()` PHPDoc 補上遺漏的 array shape keys

**檔案**：`src/traits/PluginTrait.php:304-316`

**問題**：PHPDoc `@param array{...}` 沒列出 `link`、`priority`、`lc`、`env` 等實際被讀取的 key。

**修法**：補齊 `@param` 中的 array shape。

**Breaking**：否

---

## Phase 3：Minor 程式碼品質

### m1. 加 `declare(strict_types=1)`
**延後到下個 major 版本**（會讓下游傳 `'123'` 給 int 失敗）

### m2. `public static` 屬性改 private + getter
**延後到下個 major 版本**

### m3. PHPDoc 縮排與中英文混用
跑一次 phpcbf 自動修復 + 統一星號對齊

### m4. `$kebab`/`$snake` 用 `sanitize_title()` 處理 non-ASCII
**警示**：對中文 `app_name` 行為會變（算 breaking）。建議改為新增 optional `slug` 參數讓使用者明確傳入

### m5. `register_required_plugins()` 移除 `phpcs:disable`，改短陣列語法

### m6. trait 內 method 統一加 `final`
- `set_puc_pat`、`plugin_update_checker`、`load_textdomain`、`add_module_handle`：加 final
- **`activate()` / `deactivate()` 必須保留可 override**（CLAUDE.md 明示），用 `phpcs:disable` 標例外

```php
// phpcs:disable Universal.FunctionDeclarations.RequireFinalMethodsInTraits
public function activate(): void {}
public function deactivate(): void {}
// phpcs:enable Universal.FunctionDeclarations.RequireFinalMethodsInTraits
```

### m8. `check_required_plugins()` 補 `@param array<string, mixed>` PHPDoc

### m9. `set_const` 的 `Version` 加 fallback
```php
self::$version = ( $plugin_data['Version'] ?? '' ) ?: '0.0.0';
```

### m10. 移除 `$app_name = 'production'` 寫死預設值的歧義

### m11. `SingletonTrait::instance()` 的 `phpcs:ignore` 補上 sniff 名稱

### m12. `load_textdomain()` 補 `@return void`

### m13. `$module_handles` PHPDoc 修正為 `@var array<string, string>`

---

## CI 配置議題（順便檢查）

審查發現 `set_puc_pat()` 等 method 沒加 `final` 卻沒被 phpcs 擋下，**代表 CI 規則可能漏跑**。

**建議**：本次 PR 順便：
1. 確認 `phpcs.xml` 是否有意外排除 `Universal.FunctionDeclarations.RequireFinalMethodsInTraits`
2. 跑一次完整 `vendor/bin/phpcs` 看是否有 baseline / ignore 漏網
3. 確認 GitHub Actions（如有）的 phpcs job 真的執行

---

## 執行順序建議

### PR #1：純安全 + 純 bug fix（零 breaking 風險）
- **C1**（get_allowed_domains 驗證 + host 精確比對）
- **M6**（register_required_plugins textdomain 修正）
- **M8**（cache key namespace）
- **m9**（Version fallback）

→ 最小且最關鍵，先合進去

### PR #2：邏輯與防呆強化
- **C3**（load_template 移除 sentinel）
- **C4**（路徑遍歷防護）
- **M7**（add_type_attribute escape）
- **M10**（getFileName false 處理）
- **M9**（hook 重複註冊防護）

### PR #3：架構小幅改進
- **M1**（SingletonTrait `new static`）
- **M2**（PHPDoc nullable）
- **M3**（PUC 延後到 admin/cron）
- **M4**（後門字串抽常數）
- **M5**（init PHPDoc 補齊）

### PR #4：code quality + final 規範化
- **C2**（set_puc_pat 防呆 + final）
- **m6**（其他 method 加 final，前置：先掃下游確認無 override）
- **m3 / m5 / m11 / m12 / m13**（PHPDoc / phpcs 整理）
- 順便修 CI phpcs 規則漏跑問題

### 下個 major 版本（v2.0.0）
- **m1**（strict_types）
- **m2**（public static → private + getter）
- **m4**（`$kebab` 用 sanitize_title）
- 用 DTO 封裝 `init()` 參數
- 用 enum 取代 `$need_lc` 三態

---

## 驗證清單

每個 PR 合併前：

- [ ] `composer lint` 全綠
- [ ] `vendor/bin/phpstan analyse` 無新增警告
- [ ] 至少在一個下游 plugin 內手動 require 並啟動，確認：
  - [ ] `init()` 流程不丟例外
  - [ ] `load_template()` 載入元件正常
  - [ ] LC 流程（有授權 / 無授權 / allowed_domain）三種情境
  - [ ] PUC 在 admin 進入時觸發更新檢查
  - [ ] i18n 字串可被 `.po` 覆蓋（M6 修完後）
- [ ] 同步更新 `composer.json` 版本號
- [ ] CHANGELOG 註記行為改變的項目（特別是 PUC 延後、後門字串常數化）

---

## 向後相容性總結

| 修改項 | Breaking? | 風險 |
|--------|-----------|------|
| C1 get_allowed_domains 驗證 | 否 | 私有方法 |
| C2 set_puc_pat 加 final | **是** | 須先掃下游 override 情況 |
| C3 load_template 重構 | 否 | public signature 不變 |
| C4 路徑遍歷防護 | 否 | 純防禦性 |
| M1 SingletonTrait `$instances` | 否 | 屬性是 private |
| M3 PUC 延後 | 否 | API 不變，但 `$puc_pat` 初始化時機變晚 |
| M4 後門字串抽常數 | 否 | 支援值不變 |
| M6 textdomain 修正 | 否 | 純 bug fix |
| M7 add_type_attribute escape | 否 | 簽章不動 |
| M8 cache key prefix | 否 | 私有實作 |
| m1 strict_types | **是** | 延後到 v2.0 |
| m2 public static → private | **是** | 延後到 v2.0 |
| m4 sanitize_title | **是**（中文 app_name） | 延後到 v2.0 |
| m6 加 final | **是**（若下游 override） | 須先掃下游 |
