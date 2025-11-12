<?php
/**
 * Plugin trait
 *
 * @package J7\WpUtils
 */

namespace J7\WpUtils\Traits;

if (trait_exists('PluginTrait')) {
	return;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

trait PluginTrait {


	/** @var string App Name */
	public static $app_name = '';

	/** @var string Kebab Name */
	public static $kebab = '';

	/** @var string Snake Name */
	public static $snake = '';

	/** @var 'local'|'development'|'staging'|'production' Env Name */
	public static $env = 'production';

	/** @var string Github Repo URL */
	public static $github_repo = '';

	/** @var string Plugin Update Checker Personal Access Token */
	public static $puc_pat;

	/** @var string Plugin Directory */
	public static $dir;

	/** @var string Plugin URL */
	public static $url;

	/** @var string Plugin Version */
	public static $version;

	/** @var string Plugin Capability */
	public static $capability = 'manage_options';

	/** @var bool|string Need LC */
	public static $need_lc = true;

	/** @var int Plugin Menu Position */
	public static $submenu_position = 10;

	/** @var bool Hide Submenu */
	public static $hide_submenu = false;

	/** @var callable Submenu Callback */
	public static $submenu_callback = '';

	/** @var string Template Path */
	public static $template_path = '/inc';

	/** @var array Template Page Names */
	public static $template_page_names = [ '404' ];
	/** @var array Callback after check required plugins */
	protected static $callback;
	/** @var array Callback Args */
	protected static $callback_args = [];
	/** @var string Plugin Entry File */
	protected static $plugin_entry_path;
	/** @var array<handle: string, strategy:string> 要使用 type="module" 的 script handle */
	private static $module_handles = [];
	/** @var array Required plugins */
	public $required_plugins = [
		// array(
		// 'name'     => 'WooCommerce',
		// 'slug'     => 'woocommerce',
		// 'required' => true,
		// 'version'  => '7.6.0',
		// ),
		// array(
		// 'name'     => 'WP Toolkit',
		// 'slug'     => 'wp-toolkit',
		// 'source'   => 'https://github.com/j7-dev/wp-toolkit/releases/latest/download/wp-toolkit.zip',
		// 'required' => true,
		// ),
	];

	/**
	 * Add LC menu
	 */
	final public static function add_lc_menu(): void {
		if (self::$hide_submenu) {
			return;
		}

		$instance         = \J7_Required_Plugins::get_instance(self::$kebab);
		$is_j7rp_complete = $instance->is_j7rp_complete();

		if (!$is_j7rp_complete) {
			return;
		}

		$ia = true;
		if (class_exists('\J7\Powerhouse\LC')) {
			$ia = \J7\Powerhouse\LC::ia(self::$kebab);
		}

		\add_submenu_page(
			'powerhouse',
			self::$app_name,
			self::$app_name,
			self::$capability,
			self::$kebab,
			( true === self::$need_lc && !$ia ) ? [ __CLASS__, 'redirect' ] : self::$submenu_callback,
			self::$submenu_position
		);
	}

	/**
	 * Redirect to the admin page.
	 *
	 * @return void
	 */
	final public static function redirect(): void {
		if (!class_exists('\J7\Powerhouse\Bootstrap')) {
			return;
		}
		// @phpstan-ignore-next-line
		\wp_redirect(\admin_url('admin.php?page=' . \J7\Powerhouse\Bootstrap::LC_MENU_SLUG));
		exit;
	}

	/**
	 * 從指定的模板路徑讀取模板文件並渲染數據
	 *
	 * @deprecated 0.2.6 以後，改用 load_template
	 * @param string $name 指定路徑裡面的文件名
	 * @param mixed  $args 要渲染到模板中的數據
	 * @param bool   $output 是否輸出
	 * @param bool   $load_once 是否只載入一次
	 *
	 * @return ?string
	 * @throws \Exception 如果模板文件不存在.
	 */
	final public static function get(
		string $name,
		mixed $args = null,
		?bool $output = true,
		?bool $load_once = false,
	): ?string {
		return self::load_template($name, $args, $output, $load_once);
	}

	/**
	 * 從指定的模板路徑讀取模板文件並渲染數據
	 *
	 * @param string $name 指定路徑裡面的文件名
	 * @param mixed  $args 要渲染到模板中的數據
	 * @param bool   $output 是否輸出
	 * @param bool   $load_once 是否只載入一次
	 *
	 * @return ?string
	 * @throws \Exception 如果模板文件不存在.
	 */
	final public static function load_template(
		string $name,
		mixed $args = null,
		?bool $output = true,
		?bool $load_once = false,
	): ?string {
		$result = self::safe_load_template($name, $args, $output, $load_once);
		if (' ' === $result) {
			throw new \Exception("模板文件 {$name} 不存在");
		}

		return $result;
	}

	/**
	 * 從指定的模板路徑讀取模板文件並渲染數據
	 *
	 * @param string $name 指定路徑裡面的文件名
	 * @param mixed  $args 要渲染到模板中的數據
	 * @param bool   $echo 是否輸出
	 * @param bool   $load_once 是否只載入一次
	 *
	 * @return string|false|null
	 * @throws \Exception 如果模板文件不存在.
	 */
	final public static function safe_load_template(
		string $name,
		mixed $args = null,
		?bool $echo = true,
		?bool $load_once = false,
	): string|false|null {

		// 如果 $name 是以 page name 開頭的，那就去 page folder 裡面找
		$is_page = false;
		foreach (self::$template_page_names as $page_name) {
			if (str_starts_with($name, $page_name)) {
				$is_page = true;
				break;
			}
		}

		$folder = $is_page ? 'pages' : 'components';

		$template_path = self::$dir . self::$template_path . "/templates/{$folder}/{$name}";

		// 檢查模板文件是否存在
		if (file_exists("{$template_path}.php")) {
			if ($echo) {
				\load_template("{$template_path}.php", $load_once, $args);

				return null;
			}
			ob_start();
			\load_template("{$template_path}.php", $load_once, $args);

			return ob_get_clean();
		} elseif (file_exists("{$template_path}/index.php")) {
			if ($echo) {
				\load_template("{$template_path}/index.php", $load_once, $args);

				return null;
			}
			ob_start();
			\load_template("{$template_path}/index.php", $load_once, $args);

			return ob_get_clean();
		}

		return ' ';
	}

	/**
	 * 從指定的模板路徑讀取模板文件並渲染數據
		 *
	 * @deprecated 0.2.6 以後，改用 safe_load_template
	 * @param string $name 指定路徑裡面的文件名
	 * @param mixed  $args 要渲染到模板中的數據
	 * @param bool   $echo 是否輸出
	 * @param bool   $load_once 是否只載入一次
	 *
	 * @return string|false|null
	 * @throws \Exception 如果模板文件不存在.
	 */
	final public static function safe_get(
		string $name,
		mixed $args = null,
		?bool $echo = true,
		?bool $load_once = false,
	): string|false|null {
		return self::safe_load_template($name, $args, $echo, $load_once);
	}

	/**
	 * Add type="module" attribute to script tag
	 *
	 * @param string $tag The script tag.
	 * @param string $handle The script handle.
	 * @param string $src The script src.
	 * @return string
	 */
	final public static function add_type_attribute( $tag, $handle, $src ) {
		if (!in_array($handle, array_keys(self::$module_handles))) {
			return $tag;
		}
		// change the script tag by adding type="module" and return it.
		$tag = sprintf(
		/*html*/'<script type="module" src="%1$s" %2$s></script>', // phpcs:ignore
			\esc_url($src),
			self::$module_handles[ $handle ]
		);
		return $tag;
	}

	/**
	 * 取得設定
	 *
	 * @deprecated 0.2.6 以後，改用 DTO
	 * @param string|null $key 設定 key
	 * @param string|null $default 預設值
	 * @return string|array
	 */
	final public static function get_settings( ?string $key = null, ?string $default = null ): string|array {
		$settings = \get_option(self::$snake . '_settings', []);
		if (!\is_array($settings)) {
			$settings = [];
		}
		if (!$key) {
			return $settings;
		}

		return $settings[ $key ] ?? $default;
	}

	/**
	 * Init
	 * Set the app_name, github_repo, callback, callback_args
	 *
	 * @param array{
	 * app_name: string,
	 * github_repo: string,
	 * callback: callable,
	 * callback_args?: array<mixed>,
	 * lc?: boolean|string,
	 * template_path?: string,
	 * template_page_names?: array<string>,
	 * capability?: string,
	 * submenu_position?: int,
	 * hide_submenu?: boolean,
	 * submenu_callback?: callable,
	 * } $args The arguments.
	 *
	 * @return void
	 * @example set_const( array( 'app_name' => 'My App', 'github_repo' => '', 'callback' => array($this, 'func') ) );
	 */
	final public function init( array $args ): void {
		$priority = $args['priority'] ?? 10;
		$this->set_const($args);

		\register_activation_hook(self::$plugin_entry_path, [ $this, 'activate' ]);
		\register_deactivation_hook(self::$plugin_entry_path, [ $this, 'deactivate' ]);
		\add_action('plugins_loaded', fn() => $this->check_required_plugins($args), $priority);
		\add_filter('script_loader_tag', [ $this, 'add_type_attribute' ], 10, 3);
		\add_action('init', [ $this, 'load_textdomain' ]);

		$this->register_required_plugins();
		$this->set_puc_pat();
		$this->plugin_update_checker();
	}

	/**
 * Set const
 * Set the app_name, github_repo
 *
 * @param array $args The arguments.
 *
 * @return void
 * @example set_const( array( 'app_name' => 'My App', 'github_repo' => '' ) );
 */
	final public function set_const( array $args ): void {
		self::$app_name      = $args['app_name'];
		self::$kebab         = strtolower(str_replace([ ' ', '_' ], '-', $args['app_name']));
		self::$snake         = strtolower(str_replace([ ' ', '-' ], '_', $args['app_name']));
		self::$env           = $args['env'] ?? \wp_get_environment_type();
		self::$github_repo   = $args['github_repo'];
		self::$callback      = $args['callback'];
		self::$callback_args = $args['callback_args'] ?? [];
		if (isset($args['template_path'])) {
			self::$template_path = $args['template_path'];
		}
		if (isset($args['template_page_names'])) {
			self::$template_page_names = $args['template_page_names'];
		}

		$reflector               = new \ReflectionClass(get_called_class());
		self::$plugin_entry_path = $reflector?->getFileName();

		self::$dir = \untrailingslashit(\wp_normalize_path(\plugin_dir_path(self::$plugin_entry_path)));
		self::$url = \untrailingslashit(\plugin_dir_url(self::$plugin_entry_path));
		if (!\function_exists('get_plugin_data')) {
			require_once \ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data            = \get_plugin_data(self::$plugin_entry_path, true, false);
		self::$version          = $plugin_data['Version'];
		self::$capability       = $args['capability'] ?? 'manage_options';
		self::$submenu_position = $args['submenu_position'] ?? 10;
		self::$hide_submenu     = $args['hide_submenu'] ?? false;
		self::$submenu_callback = $args['submenu_callback'] ?? '';
	}

	/**
	 * Check required plugins
	 *
	 * @return void
	 */
	final public function check_required_plugins( array $args ): void {
		$this->set_lc($args);

		$instance         = \J7_Required_Plugins::get_instance(self::$kebab);
		$is_j7rp_complete = $instance->is_j7rp_complete();

		if (!$is_j7rp_complete) {
			return;
		}
		if (!is_callable(self::$callback)) {
			return;
		}

		$ia = true;
		if (class_exists('\J7\Powerhouse\LC')) {
			$ia = \J7\Powerhouse\LC::ia(self::$kebab);
		}

		if (true !== self::$need_lc || $ia) {
			call_user_func_array(self::$callback, self::$callback_args);
		}
	}

	/**
	 * Set LC
	 *
	 * @param array $args The arguments.
	 *
	 * @return void
	 */
	final public function set_lc( array $args ): void {
		if (isset($args['lc'])) {
			$fa            = in_array($args['lc'], [ 'ZmFsc2', false ], true);
			$in            = in_array($args['lc'], [ 'c2tpcA', 'skip' ], true);
			self::$need_lc = $in ? $args['lc'] : !$fa;
		} else {
			self::$need_lc = \class_exists('\J7\Powerhouse\LC');
		}

		// 判斷網域
		$allowed_domains = $this->get_allowed_domains();
		$site_url        = \site_url();
		foreach ($allowed_domains as $domain) {
			if (strpos($site_url, $domain) !== false) {
				self::$need_lc = false;
				break;
			}
		}

		\add_action('admin_menu', [ __CLASS__, 'add_lc_menu' ], 20);

		// TODO 之後可以改成 !\class_exists('\J7\Powerhouse')
		if (false ===self::$need_lc || !\class_exists('\J7\Powerhouse\LC')) {
			return;
		}

		\add_filter(
			'powerhouse_product_infos',
			function ( $product_infos ) {
				return $product_infos + [
					self::$kebab => [
						'name' => self::$app_name,
						'link' => $args['link'] ?? '',
					],
				];
			}
		);
	}

	/** @return array<string> 取得允許的域名清單 */
	private function get_allowed_domains(): array {
		try {
			$allowed_domains = \get_transient('allowed_domains');
			if ($allowed_domains !== false) {
				return $allowed_domains;
			}

			$response = \wp_remote_get(
				'https://cloud.luke.cafe/wp-json/power-partner-server/allow_domains',
				[
					'timeout' => 60,
				]
				);

			if (\is_wp_error($response)) {
				throw new \Exception('Failed to fetch allowed domains');
			}

			$body        = \wp_remote_retrieve_body($response);
			$domain_list = \json_decode($body, true);

			if (!\is_array($domain_list) || !$domain_list) {
				throw new \Exception('domain_list is not array or domain_list is empty');
			}
			\set_transient('allowed_domains', $allowed_domains, 7 * \DAY_IN_SECONDS);
			return $allowed_domains;
		} catch (\Throwable $th) {
			// 出錯就給預設值
			return [
				'wp-mak.ing',
				'instawp.co',
				'instawp.xyz',
				'wpsite.pro',
				'wpsite2.pro',
				'site-now.app',
			];
		}
	}

	/**
	 * Register required plugins
	 *
	 * @return void
	 */
	final public function register_required_plugins(): void
    { // phpcs:ignore
        // phpcs:disable
        $config = array(
            'id' => self::$kebab, // Unique ID for hashing notices for multiple instances of TGMPA.
            'default_path' => '', // Default absolute path to bundled plugins.
            'menu' => 'tgmpa-install-plugins', // Menu slug.
            'parent_slug' => 'plugins.php', // Parent menu slug.
            'capability' => 'manage_options', // Capability needed to view plugin install page, should be a capability associated with the parent menu used.
            'has_notices' => true, // Show admin notices or not.
            'dismissable' => false, // If false, a user cannot dismiss the nag message.
            'dismiss_msg' => \__('這個訊息將在依賴套件被安裝並啟用後消失。' . self::$app_name . ' 沒有這些依賴套件的情況下將無法運作！', 'wp_react_plugin'), // If 'dismissable' is false, this message will be output at top of nag.
            'is_automatic' => true, // Automatically activate plugins after installation or not.
            'message' => '', // Message to output right before the plugins table.
            'strings' => array(
                'page_title' => \__('安裝依賴套件', 'wp_react_plugin'),
                'menu_title' => \__('安裝依賴套件', 'wp_react_plugin'),
                'installing' => \__('安裝套件: %s', 'wp_react_plugin'), // translators: %s: plugin name.
                'updating' => \__('更新套件: %s', 'wp_react_plugin'), // translators: %s: plugin name.
                'oops' => \__('OOPS! plugin API 出錯了', 'wp_react_plugin'),
                'notice_can_install_required' => \_n_noop(
                // translators: 1: plugin name(s).
                    self::$app_name . ' 依賴套件: %1$s.',
                    self::$app_name . ' 依賴套件: %1$s.',
                    'wp_react_plugin'
                ),
                'notice_can_install_recommended' => \_n_noop(
                // translators: 1: plugin name(s).
                    self::$app_name . ' 推薦套件: %1$s.',
                    self::$app_name . ' 推薦套件: %1$s.',
                    'wp_react_plugin'
                ),
                'notice_ask_to_update' => \_n_noop(
                // translators: 1: plugin name(s).
                    '以下套件需要更新到最新版本來兼容 ' . self::$app_name . ': %1$s.',
                    '以下套件需要更新到最新版本來兼容 ' . self::$app_name . ': %1$s.',
                    'wp_react_plugin'
                ),
                'notice_ask_to_update_maybe' => \_n_noop(
                // translators: 1: plugin name(s).
                    '以下套件有更新: %1$s.',
                    '以下套件有更新: %1$s.',
                    'wp_react_plugin'
                ),
                'notice_can_activate_required' => \_n_noop(
                // translators: 1: plugin name(s).
                    '以下依賴套件目前為停用狀態: %1$s.',
                    '以下依賴套件目前為停用狀態: %1$s.',
                    'wp_react_plugin'
                ),
                'notice_can_activate_recommended' => \_n_noop(
                // translators: 1: plugin name(s).
                    '以下推薦套件目前為停用狀態: %1$s.',
                    '以下推薦套件目前為停用狀態: %1$s.',
                    'wp_react_plugin'
                ),
                'install_link' => \_n_noop(
                    '安裝套件',
                    '安裝套件',
                    'wp_react_plugin'
                ),
                'update_link' => \_n_noop(
                    '更新套件',
                    '更新套件',
                    'wp_react_plugin'
                ),
                'activate_link' => \_n_noop(
                    '啟用套件',
                    '啟用套件',
                    'wp_react_plugin'
                ),
                'return' => \__('回到安裝依賴套件', 'wp_react_plugin'),
                'plugin_activated' => \__('套件啟用成功', 'wp_react_plugin'),
                'activated_successfully' => \__('以下套件已成功啟用:', 'wp_react_plugin'),
                // translators: 1: plugin name.
                'plugin_already_active' => \__('沒有執行任何動作 %1$s 已啟用', 'wp_react_plugin'),
                // translators: 1: plugin name.
                'plugin_needs_higher_version' => \__(self::$app_name . ' 未啟用。' . self::$app_name . ' 需要新版本的 %s 。請更新套件。', 'wp_react_plugin'),
                // translators: 1: dashboard link.
                'complete' => \__('所有套件已成功安裝跟啟用 %1$s', 'wp_react_plugin'),
                'dismiss' => \__('關閉通知', 'wp_react_plugin'),
                'notice_cannot_install_activate' => \__('有一個或以上的依賴/推薦套件需要安裝/更新/啟用', 'wp_react_plugin'),
                'contact_admin' => \__('請聯繫網站管理員', 'wp_react_plugin'),

                'nag_type' => 'error', // Determines admin notice type - can only be one of the typical WP notice classes, such as 'updated', 'update-nag', 'notice-warning', 'notice-info' or 'error'. Some of which may not work as expected in older WP versions.
            ),
        );

        \j7rp($this->required_plugins, $config);
    }

    /**
     * Set Plugin Update Checker Personal Access Token
     *
     * @return void
     */
    public static function set_puc_pat(): void
    {
        $puc_pat_file = self::$dir . '/.puc_pat';
        // Check if .env file exists
        if (\file_exists($puc_pat_file)) {
            // Read contents of .env file and base64 decode it
            $env_contents = \trim(\file_get_contents($puc_pat_file));
            self::$puc_pat = \base64_decode( $env_contents );
        }
    }

    /**
     * Plugin update checker
     * When you push a new release to GitHub, user will receive updates in wp-admin/plugins.php page
     *
     * @return void
     */
    public function plugin_update_checker(): void
    {
        try {
            $update_checker = PucFactory::buildUpdateChecker(
                self::$github_repo,
                self::$plugin_entry_path,
                self::$kebab
            );

            /** @var \Puc_v4p4_Vcs_PluginUpdateChecker $update_checker */
            $update_checker->setBranch('master');
            // if your repo is private, you need to set authentication
            if (self::$puc_pat) {
                $update_checker->setAuthentication(self::$puc_pat);
            }
            $update_checker->getVcsApi()->enableReleaseAssets();
        } catch (\Throwable $th) {}
    }

	/**
	 * Add module handle
	 * @param string $handle The script handle.
	 * @param string $strategy The strategy.
	 * @return void
	 */
	public function add_module_handle( string $handle, string $strategy = 'async' ): void {
		self::$module_handles[$handle] = $strategy;
	}

	/**
	 * Load textdomain i18n
	 */
	public function load_textdomain(): void {
		\load_plugin_textdomain(self::$snake, false, self::$dir . '/languages');
	}

	/**
	 * Activate
	 *
	 * @return void
	 */
	final public function activate(): void
	{
	}

	/**
	 * Deactivate
	 *
	 * @return void
	 */
	final public function deactivate(): void
	{
	}
}
