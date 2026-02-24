<?php
/**
 * WordPress Diagnostic Tool — WP-CLI Mode
 *
 * Script troubleshoot WordPress menggunakan WP-CLI.
 * PERHATIAN: Hapus file ini setelah proses diagnosa selesai!
 *
 * Compatible: PHP 7.4+
 * Letakkan di root WordPress (direktori yang sama dengan wp-config.php)
 */

// ============================================================
// SECURITY: Basic protection - only allow direct access
// ============================================================
if (php_sapi_name() === 'cli') {
    die("Run this script via web browser.\n");
}

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: text/html; charset=UTF-8');

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Jalankan perintah WP-CLI dan kembalikan output-nya.
 *
 * @param string $command  Perintah WP-CLI (tanpa "wp" di depan)
 * @param bool   $json     Apakah output berformat JSON
 * @return array ['success' => bool, 'output' => string, 'raw' => string]
 */
function run_wp_cli(string $command, bool $json = false): array
{
    global $wp_cli_path, $wp_root;

    $wp_flag  = '--path=' . escapeshellarg($wp_root);
    $no_color = '--no-color';
    $allow_root = '--allow-root';

    $full_cmd = escapeshellcmd($wp_cli_path)
        . ' ' . $command
        . ' ' . $wp_flag
        . ' ' . $no_color
        . ' ' . $allow_root
        . ' 2>&1';

    $output = '';
    $return_code = 0;

    if (function_exists('proc_open')) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($full_cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $return_code = proc_close($process);
        } else {
            return ['success' => false, 'output' => 'proc_open gagal membuka proses.', 'raw' => ''];
        }
    } elseif (function_exists('shell_exec')) {
        $output = (string) shell_exec($full_cmd);
        $return_code = 0; // shell_exec tidak mengembalikan exit code
    } else {
        return ['success' => false, 'output' => 'shell_exec dan proc_open tidak tersedia.', 'raw' => ''];
    }

    $output = trim($output);

    if ($json) {
        $decoded = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['success' => true, 'output' => $decoded, 'raw' => $output];
        }
    }

    return [
        'success' => ($return_code === 0),
        'output'  => $output,
        'raw'     => $output,
    ];
}

/**
 * Deteksi path WP-CLI.
 *
 * @return string|false Path ke wp binary atau false jika tidak ditemukan
 */
function detect_wp_cli()
{
    $candidates = [
        'wp',
        '/usr/local/bin/wp',
        '/usr/bin/wp',
        '/home/' . get_current_user() . '/.composer/vendor/bin/wp',
        '/root/.composer/vendor/bin/wp',
        dirname(__FILE__) . '/wp-cli.phar',
    ];

    foreach ($candidates as $candidate) {
        $check = shell_exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null');
        if (!empty(trim((string)$check))) {
            return trim((string)$check);
        }
        // Cek langsung apakah file executable
        if (file_exists($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return false;
}

/**
 * Render badge status HTML.
 *
 * @param string $status 'ok' | 'warning' | 'error' | 'info'
 * @param string $label  Teks label
 * @return string HTML badge
 */
function badge(string $status, string $label): string
{
    $colors = [
        'ok'      => ['bg' => '#d4edda', 'border' => '#28a745', 'text' => '#155724'],
        'warning' => ['bg' => '#fff3cd', 'border' => '#ffc107', 'text' => '#856404'],
        'error'   => ['bg' => '#f8d7da', 'border' => '#dc3545', 'text' => '#721c24'],
        'info'    => ['bg' => '#d1ecf1', 'border' => '#17a2b8', 'text' => '#0c5460'],
    ];
    $c = $colors[$status] ?? $colors['info'];
    $icons = ['ok' => '✔', 'warning' => '⚠', 'error' => '✖', 'info' => 'ℹ'];
    $icon  = $icons[$status] ?? 'ℹ';
    return sprintf(
        '<span style="display:inline-block;padding:2px 10px;border-radius:12px;background:%s;border:1px solid %s;color:%s;font-weight:600;font-size:0.85em;">%s %s</span>',
        $c['bg'], $c['border'], $c['text'], $icon, htmlspecialchars($label)
    );
}

/**
 * Render satu baris tabel diagnosa.
 *
 * @param string $label   Nama pemeriksaan
 * @param string $value   Nilai / deskripsi
 * @param string $status  'ok' | 'warning' | 'error' | 'info'
 * @param string $note    Catatan tambahan (opsional)
 * @return string HTML <tr>
 */
function row(string $label, string $value, string $status = 'info', string $note = ''): string
{
    $note_html = $note ? '<br><small style="color:#666;">' . htmlspecialchars($note) . '</small>' : '';
    return sprintf(
        '<tr>
            <td style="padding:8px 12px;border-bottom:1px solid #dee2e6;font-weight:500;white-space:nowrap;">%s</td>
            <td style="padding:8px 12px;border-bottom:1px solid #dee2e6;word-break:break-all;">%s%s</td>
            <td style="padding:8px 12px;border-bottom:1px solid #dee2e6;text-align:center;">%s</td>
        </tr>',
        htmlspecialchars($label),
        htmlspecialchars($value),
        $note_html,
        badge($status, strtoupper($status))
    );
}

/**
 * Render header section tabel.
 *
 * @param string $title Judul section
 * @param string $icon  Emoji icon
 * @return string HTML
 */
function section_header(string $title, string $icon = '🔍'): string
{
    return sprintf(
        '<tr><th colspan="3" style="padding:12px 16px;background:#343a40;color:#fff;font-size:1em;letter-spacing:0.5px;">%s %s</th></tr>',
        $icon,
        htmlspecialchars($title)
    );
}

// ============================================================
// INISIALISASI
// ============================================================

$wp_root    = rtrim(dirname(__FILE__), '/\\');
$start_time = microtime(true);

// Deteksi WP-CLI
$wp_cli_available = false;
$wp_cli_path      = '';
$wp_cli_version   = 'Tidak diketahui';

if (function_exists('shell_exec') || function_exists('proc_open')) {
    $detected = detect_wp_cli();
    if ($detected !== false) {
        $wp_cli_path      = $detected;
        $wp_cli_available = true;
        $ver_result       = run_wp_cli('--version');
        if ($ver_result['success'] || !empty($ver_result['output'])) {
            $wp_cli_version = $ver_result['output'];
        }
    }
}

// ============================================================
// KUMPULKAN DATA DIAGNOSA
// ============================================================

$sections = [];

// ----------------------------------------------------------
// 1. WP-CLI Detection
// ----------------------------------------------------------
$s1 = [];
if ($wp_cli_available) {
    $s1[] = row('WP-CLI Tersedia', 'Ya — ' . $wp_cli_path, 'ok');
    $s1[] = row('WP-CLI Versi', $wp_cli_version, 'info');
} else {
    $s1[] = row('WP-CLI Tersedia', 'Tidak ditemukan', 'error', 'Install WP-CLI: https://wp-cli.org/#installing');
    $s1[] = row('shell_exec / proc_open', function_exists('shell_exec') ? 'Tersedia' : 'Dinonaktifkan', function_exists('shell_exec') ? 'ok' : 'error');
}
$sections['WP-CLI Detection'] = ['icon' => '🔧', 'rows' => $s1];

// ----------------------------------------------------------
// 2. Server Environment
// ----------------------------------------------------------
$s2 = [];

$php_version = PHP_VERSION;
$php_status  = version_compare($php_version, '8.0', '>=') ? 'ok' : (version_compare($php_version, '7.4', '>=') ? 'warning' : 'error');
$s2[] = row('PHP Version', $php_version, $php_status, $php_status === 'warning' ? 'Disarankan PHP 8.0+' : '');

$server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Tidak diketahui';
$s2[] = row('Server Software', $server_software, 'info');

$memory_limit = ini_get('memory_limit');
$memory_bytes = return_bytes($memory_limit);
$mem_status   = ($memory_bytes >= 256 * 1024 * 1024) ? 'ok' : (($memory_bytes >= 128 * 1024 * 1024) ? 'warning' : 'error');
$s2[] = row('Memory Limit', $memory_limit, $mem_status, $mem_status !== 'ok' ? 'WordPress merekomendasikan minimal 256M' : '');

$max_exec = ini_get('max_execution_time');
$exec_status = ($max_exec == 0 || $max_exec >= 60) ? 'ok' : 'warning';
$s2[] = row('Max Execution Time', $max_exec . ' detik', $exec_status);

$upload_max = ini_get('upload_max_filesize');
$s2[] = row('Upload Max Filesize', $upload_max, 'info');

$post_max = ini_get('post_max_size');
$s2[] = row('Post Max Size', $post_max, 'info');

$s2[] = row('OS', PHP_OS_FAMILY . ' (' . php_uname('r') . ')', 'info');
$s2[] = row('Server IP', $_SERVER['SERVER_ADDR'] ?? 'N/A', 'info');
$s2[] = row('Document Root', $_SERVER['DOCUMENT_ROOT'] ?? 'N/A', 'info');

$sections['Server Environment'] = ['icon' => '🖥️', 'rows' => $s2];

// ----------------------------------------------------------
// 3. WordPress Core Check
// ----------------------------------------------------------
$s3 = [];

if ($wp_cli_available) {
    // wp core is-installed
    $installed = run_wp_cli('core is-installed');
    $s3[] = row('WP Terinstall', $installed['success'] ? 'Ya' : 'Tidak / Error: ' . $installed['output'], $installed['success'] ? 'ok' : 'error');

    // wp core version
    $core_ver = run_wp_cli('core version');
    $s3[] = row('WordPress Version', $core_ver['success'] ? $core_ver['output'] : 'Gagal: ' . $core_ver['output'], $core_ver['success'] ? 'ok' : 'error');

    // wp core verify-checksums (opsional, bisa lambat)
    // Dinonaktifkan untuk performa

    // wp option get blogname
    $blogname = run_wp_cli('option get blogname');
    $s3[] = row('Blog Name', $blogname['success'] ? $blogname['output'] : 'Gagal: ' . $blogname['output'], $blogname['success'] ? 'info' : 'warning');

    // wp option get siteurl
    $siteurl = run_wp_cli('option get siteurl');
    $s3[] = row('Site URL', $siteurl['success'] ? $siteurl['output'] : 'Gagal', $siteurl['success'] ? 'info' : 'warning');

    // wp option get home
    $homeurl = run_wp_cli('option get home');
    $s3[] = row('Home URL', $homeurl['success'] ? $homeurl['output'] : 'Gagal', $homeurl['success'] ? 'info' : 'warning');

    // wp config get WP_DEBUG
    $debug = run_wp_cli('config get WP_DEBUG');
    $debug_val = $debug['success'] ? trim($debug['output']) : 'Gagal';
    $debug_status = ($debug_val === '1' || strtolower($debug_val) === 'true') ? 'warning' : 'ok';
    $s3[] = row('WP_DEBUG', $debug_val, $debug_status, $debug_status === 'warning' ? 'Debug aktif — nonaktifkan di production' : '');

    // wp config get WP_DEBUG_LOG
    $debug_log = run_wp_cli('config get WP_DEBUG_LOG');
    $s3[] = row('WP_DEBUG_LOG', $debug_log['success'] ? $debug_log['output'] : 'Tidak diset / Gagal', 'info');

    // wp config get WPLANG
    $wplang = run_wp_cli('config get WPLANG');
    $s3[] = row('WP Language', $wplang['success'] && !empty($wplang['output']) ? $wplang['output'] : 'en_US (default)', 'info');

} else {
    // Fallback: baca wp-config.php langsung
    $wp_config_path = $wp_root . '/wp-config.php';
    if (file_exists($wp_config_path)) {
        $s3[] = row('wp-config.php', 'Ditemukan (WP-CLI tidak tersedia untuk detail lebih lanjut)', 'warning');
    } else {
        $s3[] = row('wp-config.php', 'Tidak ditemukan di ' . $wp_root, 'error');
    }
    $s3[] = row('WordPress Core', 'WP-CLI diperlukan untuk pemeriksaan detail', 'warning');
}

$sections['WordPress Core'] = ['icon' => '🏠', 'rows' => $s3];

// ----------------------------------------------------------
// 4. Database Check
// ----------------------------------------------------------
$s4 = [];

if ($wp_cli_available) {
    // wp db check
    $db_check = run_wp_cli('db check');
    $db_ok = $db_check['success'] && (stripos($db_check['output'], 'success') !== false || stripos($db_check['output'], 'OK') !== false || empty($db_check['output']));
    $s4[] = row('Koneksi Database', $db_ok ? 'OK' : ($db_check['output'] ?: 'Gagal'), $db_ok ? 'ok' : 'error');

    // wp db size
    $db_size = run_wp_cli('db size --size_format=mb');
    $s4[] = row('Ukuran Database', $db_size['success'] ? $db_size['output'] . ' MB' : 'Gagal: ' . $db_size['output'], $db_size['success'] ? 'info' : 'warning');

    // wp db tables
    $db_tables = run_wp_cli('db tables');
    if ($db_tables['success'] && !empty($db_tables['output'])) {
        $tables     = array_filter(explode("\n", $db_tables['output']));
        $table_count = count($tables);
        $s4[] = row('Jumlah Tabel', (string)$table_count . ' tabel', $table_count > 0 ? 'ok' : 'warning');
        $s4[] = row('Daftar Tabel', implode(', ', array_map('htmlspecialchars', $tables)), 'info');
    } else {
        $s4[] = row('Tabel Database', 'Gagal mengambil daftar tabel: ' . $db_tables['output'], 'error');
    }

    // wp db query untuk cek charset
    $db_charset = run_wp_cli('db query "SELECT @@character_set_database, @@collation_database" --skip-column-names');
    if ($db_charset['success']) {
        $s4[] = row('Charset / Collation', str_replace("\t", ' / ', $db_charset['output']), 'info');
    }

} else {
    $s4[] = row('Database Check', 'WP-CLI diperlukan untuk pemeriksaan database', 'warning');
    // Coba koneksi manual via wp-config.php
    $wp_config_path = $wp_root . '/wp-config.php';
    if (file_exists($wp_config_path)) {
        $config_content = file_get_contents($wp_config_path);
        preg_match("/define\(\s*'DB_HOST'\s*,\s*'([^']+)'\s*\)/", $config_content, $host_match);
        $db_host = $host_match[1] ?? null;
        if ($db_host) {
            $s4[] = row('DB Host (dari config)', $db_host, 'info');
        }
    }
}

$sections['Database'] = ['icon' => '🗄️', 'rows' => $s4];

// ----------------------------------------------------------
// 5. Plugin & Theme Check
// ----------------------------------------------------------
$s5 = [];

if ($wp_cli_available) {
    // Total plugin count
    $plugin_count = run_wp_cli('plugin list --format=count');
    $s5[] = row('Total Plugin', $plugin_count['success'] ? $plugin_count['output'] : 'Gagal', $plugin_count['success'] ? 'info' : 'warning');

    // Active plugins
    $active_plugins = run_wp_cli('plugin list --status=active --fields=name,version,update --format=json', true);
    if ($active_plugins['success'] && is_array($active_plugins['output'])) {
        $plugins = $active_plugins['output'];
        $s5[] = row('Plugin Aktif', count($plugins) . ' plugin', 'ok');
        foreach ($plugins as $plugin) {
            $name    = $plugin['name'] ?? 'Unknown';
            $version = $plugin['version'] ?? 'N/A';
            $update  = $plugin['update'] ?? 'none';
            $p_status = ($update === 'available') ? 'warning' : 'ok';
            $p_note   = ($update === 'available') ? 'Update tersedia' : '';
            $s5[] = row('  ↳ ' . $name, 'v' . $version, $p_status, $p_note);
        }
    } else {
        $s5[] = row('Plugin Aktif', 'Gagal mengambil data: ' . $active_plugins['raw'], 'warning');
    }

    // Inactive plugins
    $inactive_count = run_wp_cli('plugin list --status=inactive --format=count');
    $s5[] = row('Plugin Tidak Aktif', $inactive_count['success'] ? $inactive_count['output'] : 'Gagal', 'info');

    // Must-use plugins
    $mu_count = run_wp_cli('plugin list --status=must-use --format=count');
    if ($mu_count['success']) {
        $s5[] = row('Must-Use Plugin', $mu_count['output'], 'info');
    }

    // Active theme
    $active_theme = run_wp_cli('theme list --status=active --fields=name,version,update --format=json', true);
    if ($active_theme['success'] && is_array($active_theme['output'])) {
        foreach ($active_theme['output'] as $theme) {
            $t_name   = $theme['name'] ?? 'Unknown';
            $t_ver    = $theme['version'] ?? 'N/A';
            $t_update = $theme['update'] ?? 'none';
            $t_status = ($t_update === 'available') ? 'warning' : 'ok';
            $t_note   = ($t_update === 'available') ? 'Update tersedia' : '';
            $s5[] = row('Theme Aktif', $t_name . ' v' . $t_ver, $t_status, $t_note);
        }
    } else {
        $s5[] = row('Theme Aktif', 'Gagal: ' . $active_theme['raw'], 'warning');
    }

    // Total themes
    $theme_count = run_wp_cli('theme list --format=count');
    $s5[] = row('Total Theme', $theme_count['success'] ? $theme_count['output'] : 'Gagal', 'info');

} else {
    $s5[] = row('Plugin & Theme', 'WP-CLI diperlukan untuk pemeriksaan plugin dan theme', 'warning');
}

$sections['Plugin & Theme'] = ['icon' => '🧩', 'rows' => $s5];

// ----------------------------------------------------------
// 6. Performance Check
// ----------------------------------------------------------
$s6 = [];

if ($wp_cli_available) {
    // wp cache type
    $cache_type = run_wp_cli('cache type');
    $cache_val  = $cache_type['success'] ? $cache_type['output'] : 'Gagal';
    $cache_status = (stripos($cache_val, 'redis') !== false || stripos($cache_val, 'memcache') !== false) ? 'ok' : 'warning';
    $s6[] = row('Object Cache', $cache_val, $cache_status, $cache_status === 'warning' ? 'Pertimbangkan Redis/Memcached untuk performa lebih baik' : '');

    // wp cron event list
    $cron_events = run_wp_cli('cron event list --fields=hook,next_run_relative --format=json', true);
    if ($cron_events['success'] && is_array($cron_events['output'])) {
        $events = $cron_events['output'];
        $s6[] = row('WP-Cron Events', count($events) . ' event terjadwal', count($events) > 50 ? 'warning' : 'ok', count($events) > 50 ? 'Terlalu banyak cron event dapat mempengaruhi performa' : '');
        // Tampilkan 10 event pertama
        $shown = array_slice($events, 0, 10);
        foreach ($shown as $event) {
            $hook     = $event['hook'] ?? 'Unknown';
            $next_run = $event['next_run_relative'] ?? 'N/A';
            $s6[] = row('  ↳ ' . $hook, $next_run, 'info');
        }
        if (count($events) > 10) {
            $s6[] = row('  ...', '+ ' . (count($events) - 10) . ' event lainnya', 'info');
        }
    } else {
        $s6[] = row('WP-Cron Events', 'Gagal: ' . $cron_events['raw'], 'warning');
    }

    // wp option get active_plugins (jumlah)
    $transients = run_wp_cli('transient list --format=count');
    if ($transients['success']) {
        $t_count  = (int)$transients['output'];
        $t_status = $t_count > 1000 ? 'warning' : 'ok';
        $s6[] = row('Transients', $t_count . ' transient', $t_status, $t_status === 'warning' ? 'Banyak transient dapat memperlambat database' : '');
    }

} else {
    $s6[] = row('Performance Check', 'WP-CLI diperlukan untuk pemeriksaan performa', 'warning');
}

// PHP OPcache
$opcache_enabled = function_exists('opcache_get_status') && opcache_get_status(false) !== false;
$s6[] = row('PHP OPcache', $opcache_enabled ? 'Aktif' : 'Tidak aktif', $opcache_enabled ? 'ok' : 'warning', !$opcache_enabled ? 'Aktifkan OPcache untuk performa PHP lebih baik' : '');

$sections['Performance'] = ['icon' => '⚡', 'rows' => $s6];

// ----------------------------------------------------------
// 7. Security Basic Check
// ----------------------------------------------------------
$s7 = [];

// wp-config.php permissions
$wp_config_path = $wp_root . '/wp-config.php';
if (file_exists($wp_config_path)) {
    $perms = substr(sprintf('%o', fileperms($wp_config_path)), -4);
    $perm_int = octdec($perms);
    // Ideal: 400 atau 440 atau 600
    $perm_status = ($perm_int <= octdec('0640')) ? 'ok' : 'warning';
    $s7[] = row('Permission wp-config.php', $perms, $perm_status, $perm_status === 'warning' ? 'Disarankan 400 atau 440 atau 600' : '');
} else {
    $s7[] = row('wp-config.php', 'Tidak ditemukan di ' . $wp_root, 'error');
}

// wp-content permissions
$wp_content_path = $wp_root . '/wp-content';
if (is_dir($wp_content_path)) {
    $perms_content = substr(sprintf('%o', fileperms($wp_content_path)), -4);
    $perm_content_int = octdec($perms_content);
    $perm_content_status = ($perm_content_int <= octdec('0755')) ? 'ok' : 'warning';
    $s7[] = row('Permission wp-content/', $perms_content, $perm_content_status, $perm_content_status === 'warning' ? 'Disarankan 755 atau lebih ketat' : '');
} else {
    $s7[] = row('wp-content/', 'Direktori tidak ditemukan', 'error');
}

// uploads permissions
$uploads_path = $wp_root . '/wp-content/uploads';
if (is_dir($uploads_path)) {
    $perms_uploads = substr(sprintf('%o', fileperms($uploads_path)), -4);
    $s7[] = row('Permission wp-content/uploads/', $perms_uploads, 'info');
}

// DISALLOW_FILE_EDIT
if ($wp_cli_available) {
    $file_edit = run_wp_cli('config get DISALLOW_FILE_EDIT');
    $fe_val    = $file_edit['success'] ? trim($file_edit['output']) : null;
    if ($fe_val === null || $fe_val === '' || $fe_val === 'false' || $fe_val === '0') {
        $s7[] = row('DISALLOW_FILE_EDIT', $fe_val ?? 'Tidak diset', 'warning', 'Tambahkan define("DISALLOW_FILE_EDIT", true) di wp-config.php');
    } else {
        $s7[] = row('DISALLOW_FILE_EDIT', $fe_val, 'ok');
    }

    // DISALLOW_FILE_MODS
    $file_mods = run_wp_cli('config get DISALLOW_FILE_MODS');
    $fm_val    = $file_mods['success'] ? trim($file_mods['output']) : 'Tidak diset';
    $s7[] = row('DISALLOW_FILE_MODS', $fm_val, ($fm_val === '1' || $fm_val === 'true') ? 'ok' : 'info');

    // FORCE_SSL_ADMIN
    $ssl_admin = run_wp_cli('config get FORCE_SSL_ADMIN');
    $ssl_val   = $ssl_admin['success'] ? trim($ssl_admin['output']) : 'Tidak diset';
    $ssl_status = ($ssl_val === '1' || $ssl_val === 'true') ? 'ok' : 'warning';
    $s7[] = row('FORCE_SSL_ADMIN', $ssl_val, $ssl_status, $ssl_status === 'warning' ? 'Aktifkan FORCE_SSL_ADMIN untuk keamanan login' : '');
}

// .htaccess check
$htaccess_path = $wp_root . '/.htaccess';
$s7[] = row('.htaccess', file_exists($htaccess_path) ? 'Ada' : 'Tidak ditemukan', file_exists($htaccess_path) ? 'ok' : 'warning');

// xmlrpc.php
$xmlrpc_path = $wp_root . '/xmlrpc.php';
$s7[] = row('xmlrpc.php', file_exists($xmlrpc_path) ? 'Ada (pertimbangkan menonaktifkan jika tidak digunakan)' : 'Tidak ada', file_exists($xmlrpc_path) ? 'warning' : 'ok');

// readme.html
$readme_path = $wp_root . '/readme.html';
$s7[] = row('readme.html', file_exists($readme_path) ? 'Ada (sebaiknya dihapus)' : 'Tidak ada', file_exists($readme_path) ? 'warning' : 'ok');

// HTTPS
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
$s7[] = row('HTTPS', $is_https ? 'Aktif' : 'Tidak aktif', $is_https ? 'ok' : 'warning', !$is_https ? 'Gunakan HTTPS untuk keamanan' : '');

$sections['Security'] = ['icon' => '🔒', 'rows' => $s7];

// ----------------------------------------------------------
// 8. Error Log
// ----------------------------------------------------------
$s8 = [];

$error_log_path = ini_get('error_log');
$log_sources = array_filter([
    $error_log_path,
    $wp_root . '/wp-content/debug.log',
    $wp_root . '/error_log',
    $wp_root . '/error.log',
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/var/log/php_errors.log',
]);

$log_found = false;
foreach ($log_sources as $log_path) {
    if (!empty($log_path) && file_exists($log_path) && is_readable($log_path)) {
        $log_found = true;
        $file_size = filesize($log_path);
        $s8[] = row('Error Log Ditemukan', $log_path . ' (' . format_bytes($file_size) . ')', 'info');

        // Ambil 20 baris terakhir
        $lines = tail_file($log_path, 20);
        if (!empty($lines)) {
            $has_error   = false;
            $has_warning = false;
            foreach ($lines as $line) {
                if (stripos($line, 'Fatal error') !== false || stripos($line, 'Parse error') !== false) {
                    $has_error = true;
                }
                if (stripos($line, 'Warning') !== false || stripos($line, 'Notice') !== false) {
                    $has_warning = true;
                }
            }
            $log_status = $has_error ? 'error' : ($has_warning ? 'warning' : 'ok');
            $s8[] = row('Status Log', $has_error ? 'Ada Fatal/Parse Error' : ($has_warning ? 'Ada Warning/Notice' : 'Bersih'), $log_status);
        }
        break; // Gunakan log pertama yang ditemukan
    }
}

if (!$log_found) {
    $s8[] = row('Error Log', 'Tidak ditemukan atau tidak dapat dibaca', 'info', 'Aktifkan WP_DEBUG_LOG di wp-config.php untuk mencatat error');
}

$sections['Error Log'] = ['icon' => '📋', 'rows' => $s8];

// ============================================================
// HELPER FUNCTIONS (tambahan)
// ============================================================

/**
 * Konversi string memory (seperti "256M") ke bytes.
 */
function return_bytes(string $val): int
{
    $val  = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $num  = (int)$val;
    switch ($last) {
        case 'g': $num *= 1024;
        // fall through
        case 'm': $num *= 1024;
        // fall through
        case 'k': $num *= 1024;
    }
    return $num;
}

/**
 * Format bytes ke string yang mudah dibaca.
 */
function format_bytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}

/**
 * Ambil N baris terakhir dari file (seperti `tail -n`).
 */
function tail_file(string $filepath, int $lines = 20): array
{
    if (!file_exists($filepath) || !is_readable($filepath)) {
        return [];
    }
    $file = new SplFileObject($filepath, 'r');
    $file->seek(PHP_INT_MAX);
    $total_lines = $file->key();
    $start_line  = max(0, $total_lines - $lines);
    $result      = [];
    $file->seek($start_line);
    while (!$file->eof()) {
        $line = $file->current();
        if (trim($line) !== '') {
            $result[] = $line;
        }
        $file->next();
    }
    return $result;
}

// ============================================================
// HITUNG WAKTU EKSEKUSI
// ============================================================
$exec_time = round((microtime(true) - $start_time) * 1000, 2);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WordPress Diagnostic Tool — WP-CLI Mode</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            color: #212529;
            font-size: 14px;
            line-height: 1.5;
        }

        .wrapper {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #fff;
            border-radius: 12px;
            padding: 32px 36px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }
        .header h1 {
            font-size: 1.6em;
            font-weight: 700;
            letter-spacing: -0.3px;
            margin-bottom: 6px;
        }
        .header h1 span { color: #53d8fb; }
        .header .subtitle {
            color: #adb5bd;
            font-size: 0.9em;
        }
        .header .meta {
            margin-top: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 0.82em;
            color: #ced4da;
        }
        .header .meta span { display: flex; align-items: center; gap: 4px; }

        /* Warning banner */
        .warning-banner {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-left: 5px solid #ffc107;
            border-radius: 8px;
            padding: 14px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .warning-banner .icon { font-size: 1.4em; flex-shrink: 0; }
        .warning-banner strong { color: #856404; display: block; margin-bottom: 2px; }
        .warning-banner p { color: #856404; font-size: 0.9em; }

        /* WP-CLI status banner */
        .cli-banner {
            border-radius: 8px;
            padding: 12px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }
        .cli-banner.available { background: #d4edda; border: 1px solid #28a745; color: #155724; }
        .cli-banner.unavailable { background: #f8d7da; border: 1px solid #dc3545; color: #721c24; }
        .cli-banner .icon { font-size: 1.3em; }

        /* Section cards */
        .section-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            margin-bottom: 20px;
            overflow: hidden;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th {
            text-align: left;
        }
        table td {
            vertical-align: top;
        }
        table td:first-child { width: 30%; min-width: 180px; }
        table td:last-child  { width: 120px; text-align: center; }

        /* Error log pre */
        .log-container {
            background: #1e1e1e;
            color: #d4d4d4;
            border-radius: 0 0 10px 10px;
            padding: 16px 20px;
            overflow-x: auto;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.78em;
            line-height: 1.6;
            max-height: 400px;
            overflow-y: auto;
        }
        .log-container .log-line { display: block; padding: 1px 0; }
        .log-line.error-line   { color: #f48771; }
        .log-line.warning-line { color: #dcdcaa; }
        .log-line.notice-line  { color: #9cdcfe; }

        /* Summary bar */
        .summary {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            padding: 20px 24px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
        }
        .summary h3 { font-size: 1em; color: #495057; margin-right: auto; }
        .summary-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9em;
        }
        .summary-dot {
            width: 12px; height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        .dot-ok      { background: #28a745; }
        .dot-warning { background: #ffc107; }
        .dot-error   { background: #dc3545; }
        .dot-info    { background: #17a2b8; }

        /* Footer */
        .footer {
            text-align: center;
            color: #6c757d;
            font-size: 0.82em;
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #dee2e6;
        }

        /* Responsive */
        @media (max-width: 600px) {
            table td:first-child { width: 40%; }
            .header { padding: 20px; }
            .header h1 { font-size: 1.2em; }
        }

        /* Print */
        @media print {
            body { background: #fff; }
            .wrapper { max-width: 100%; padding: 0; }
            .warning-banner { display: none; }
        }
    </style>
</head>
<body>
<div class="wrapper">

    <!-- ===== HEADER ===== -->
    <div class="header">
        <h1>🩺 WordPress <span>Diagnostic Tool</span> — WP-CLI Mode</h1>
        <p class="subtitle">Laporan diagnosa lengkap instalasi WordPress dan server environment</p>
        <div class="meta">
            <span>📅 <?= date('d M Y, H:i:s T') ?></span>
            <span>🌐 <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') ?></span>
            <span>📁 <?= htmlspecialchars($wp_root) ?></span>
            <span>⏱️ Dihasilkan dalam <?= $exec_time ?> ms</span>
        </div>
    </div>

    <!-- ===== WARNING BANNER ===== -->
    <div class="warning-banner">
        <div class="icon">⚠️</div>
        <div>
            <strong>Peringatan Keamanan</strong>
            <p>Pastikan file <code>wp-diagnose.php</code> ini <strong>dihapus setelah proses diagnosa selesai</strong>. File ini mengekspos informasi sensitif server dan WordPress Anda.</p>
        </div>
    </div>

    <!-- ===== WP-CLI STATUS ===== -->
    <?php if ($wp_cli_available): ?>
    <div class="cli-banner available">
        <span class="icon">✅</span>
        <span>WP-CLI terdeteksi: <code><?= htmlspecialchars($wp_cli_path) ?></code> — <?= htmlspecialchars($wp_cli_version) ?></span>
    </div>
    <?php else: ?>
    <div class="cli-banner unavailable">
        <span class="icon">❌</span>
        <span>WP-CLI tidak tersedia. Beberapa pemeriksaan tidak dapat dilakukan. Install WP-CLI dari <a href="https://wp-cli.org/#installing" target="_blank" style="color:#721c24;">https://wp-cli.org</a> untuk diagnosa lengkap.</span>
    </div>
    <?php endif; ?>

    <!-- ===== SUMMARY ===== -->
    <?php
    $total_ok = $total_warning = $total_error = $total_info = 0;
    foreach ($sections as $section) {
        foreach ($section['rows'] as $row_html) {
            if (strpos($row_html, '>OK<') !== false)      $total_ok++;
            if (strpos($row_html, '>WARNING<') !== false) $total_warning++;
            if (strpos($row_html, '>ERROR<') !== false)   $total_error++;
            if (strpos($row_html, '>INFO<') !== false)    $total_info++;
        }
    }
    ?>
    <div class="summary">
        <h3>📊 Ringkasan Diagnosa</h3>
        <div class="summary-item"><span class="summary-dot dot-ok"></span> <strong><?= $total_ok ?></strong> OK</div>
        <div class="summary-item"><span class="summary-dot dot-warning"></span> <strong><?= $total_warning ?></strong> Warning</div>
        <div class="summary-item"><span class="summary-dot dot-error"></span> <strong><?= $total_error ?></strong> Error</div>
        <div class="summary-item"><span class="summary-dot dot-info"></span> <strong><?= $total_info ?></strong> Info</div>
    </div>

    <!-- ===== SECTIONS ===== -->
    <?php foreach ($sections as $section_name => $section_data): ?>
    <div class="section-card">
        <table>
            <thead>
                <?= section_header($section_name, $section_data['icon']) ?>
                <tr style="background:#f8f9fa;">
                    <th style="padding:8px 12px;border-bottom:2px solid #dee2e6;color:#495057;font-size:0.85em;text-transform:uppercase;letter-spacing:0.5px;">Pemeriksaan</th>
                    <th style="padding:8px 12px;border-bottom:2px solid #dee2e6;color:#495057;font-size:0.85em;text-transform:uppercase;letter-spacing:0.5px;">Nilai / Keterangan</th>
                    <th style="padding:8px 12px;border-bottom:2px solid #dee2e6;color:#495057;font-size:0.85em;text-transform:uppercase;letter-spacing:0.5px;text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($section_data['rows'] as $row_html): ?>
                    <?= $row_html ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <!-- ===== ERROR LOG CONTENT ===== -->
    <?php
    $error_log_path = ini_get('error_log');
    $log_sources_display = array_filter([
        $error_log_path,
        $wp_root . '/wp-content/debug.log',
        $wp_root . '/error_log',
        $wp_root . '/error.log',
    ]);

    foreach ($log_sources_display as $log_path) {
        if (!empty($log_path) && file_exists($log_path) && is_readable($log_path)):
            $log_lines = tail_file($log_path, 20);
            if (!empty($log_lines)):
    ?>
    <div class="section-card">
        <table>
            <thead>
                <?= section_header('20 Baris Terakhir Error Log: ' . basename($log_path), '📄') ?>
            </thead>
        </table>
        <div class="log-container">
            <?php foreach ($log_lines as $line):
                $line_class = 'log-line';
                if (stripos($line, 'Fatal error') !== false || stripos($line, 'Parse error') !== false) {
                    $line_class .= ' error-line';
                } elseif (stripos($line, 'Warning') !== false) {
                    $line_class .= ' warning-line';
                } elseif (stripos($line, 'Notice') !== false) {
                    $line_class .= ' notice-line';
                }
            ?>
                <span class="<?= $line_class ?>"><?= htmlspecialchars(rtrim($line)) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
            endif;
            break; // Tampilkan hanya log pertama yang ditemukan
        endif;
    }
    ?>

    <!-- ===== FOOTER ===== -->
    <div class="footer">
        <p>
            <strong>WordPress Diagnostic Tool — WP-CLI Mode</strong> &nbsp;|&nbsp;
            PHP <?= PHP_VERSION ?> &nbsp;|&nbsp;
            Dijalankan oleh: <?= htmlspecialchars($_SERVER['SERVER_NAME'] ?? 'localhost') ?>
        </p>
        <p style="margin-top:6px; color:#dc3545; font-weight:600;">
            ⚠️ Hapus file <code>wp-diagnose.php</code> ini segera setelah diagnosa selesai!
        </p>
    </div>

</div><!-- /.wrapper -->
</body>
</html>
