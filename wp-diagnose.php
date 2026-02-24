<?php
/**
 * WordPress Hosting Diagnostic Tool
 *
 * Alat diagnosa WordPress lengkap untuk Technical Support Hosting.
 * Mencakup: Server, PHP, Database, WordPress Core, Plugin/Theme,
 * Disk Quota, Email/SMTP, Keamanan, dan Error Log.
 *
 * PERHATIAN: Hapus file ini segera setelah diagnosa selesai!
 *
 * Compatible: PHP 7.4+
 * Letakkan di root WordPress (direktori yang sama dengan wp-config.php)
 * atau di direktori public_html untuk diagnosa server umum.
 *
 * @version 2.0
 */

// ============================================================
// SECURITY: Blokir akses CLI
// ============================================================
if (php_sapi_name() === 'cli') {
    die("Jalankan script ini melalui web browser.\n");
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
 */
function run_wp_cli(string $command, bool $json = false): array
{
    global $wp_cli_path, $wp_root;

    $wp_flag    = '--path=' . escapeshellarg($wp_root);
    $no_color   = '--no-color';
    $allow_root = '--allow-root';

    $full_cmd = escapeshellcmd($wp_cli_path)
        . ' ' . $command
        . ' ' . $wp_flag
        . ' ' . $no_color
        . ' ' . $allow_root
        . ' 2>&1';

    $output      = '';
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
            $output      = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $return_code = proc_close($process);
        } else {
            return ['success' => false, 'output' => 'proc_open gagal membuka proses.', 'raw' => ''];
        }
    } elseif (function_exists('shell_exec')) {
        $output      = (string) shell_exec($full_cmd);
        $return_code = 0;
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
        if (function_exists('shell_exec')) {
            $check = shell_exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null');
            if (!empty(trim((string)$check))) {
                return trim((string)$check);
            }
        }
        if (file_exists($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return false;
}

/**
 * Konversi string memory (seperti "256M") ke bytes.
 */
function return_bytes(string $val): int
{
    $val  = trim($val);
    if (empty($val)) return 0;
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

/**
 * Render badge status HTML.
 */
function badge(string $status, string $label): string
{
    $colors = [
        'ok'      => ['bg' => '#d4edda', 'border' => '#28a745', 'text' => '#155724'],
        'warning' => ['bg' => '#fff3cd', 'border' => '#ffc107', 'text' => '#856404'],
        'error'   => ['bg' => '#f8d7da', 'border' => '#dc3545', 'text' => '#721c24'],
        'info'    => ['bg' => '#d1ecf1', 'border' => '#17a2b8', 'text' => '#0c5460'],
    ];
    $c     = $colors[$status] ?? $colors['info'];
    $icons = ['ok' => '✔', 'warning' => '⚠', 'error' => '✖', 'info' => 'ℹ'];
    $icon  = $icons[$status] ?? 'ℹ';
    return sprintf(
        '<span style="display:inline-block;padding:2px 10px;border-radius:12px;background:%s;border:1px solid %s;color:%s;font-weight:600;font-size:0.82em;white-space:nowrap;">%s %s</span>',
        $c['bg'], $c['border'], $c['text'], $icon, htmlspecialchars($label)
    );
}

/**
 * Render satu baris tabel diagnosa.
 */
function row(string $label, string $value, string $status = 'info', string $note = ''): string
{
    $note_html = $note
        ? '<br><small style="color:#666;font-size:0.88em;">💡 ' . htmlspecialchars($note) . '</small>'
        : '';
    return sprintf(
        '<tr>
            <td style="padding:9px 14px;border-bottom:1px solid #eee;font-weight:500;white-space:nowrap;color:#343a40;">%s</td>
            <td style="padding:9px 14px;border-bottom:1px solid #eee;word-break:break-all;color:#495057;">%s%s</td>
            <td style="padding:9px 14px;border-bottom:1px solid #eee;text-align:center;">%s</td>
        </tr>',
        htmlspecialchars($label),
        htmlspecialchars($value),
        $note_html,
        badge($status, strtoupper($status))
    );
}

/**
 * Render header section tabel.
 */
function section_header(string $title, string $icon = '🔍'): string
{
    return sprintf(
        '<tr><th colspan="3" style="padding:13px 16px;background:linear-gradient(90deg,#2d3748,#4a5568);color:#fff;font-size:0.95em;letter-spacing:0.4px;">%s %s</th></tr>',
        $icon,
        htmlspecialchars($title)
    );
}

/**
 * Cek apakah ekstensi PHP aktif.
 */
function ext_row(string $ext, string $label = '', string $note = ''): string
{
    $loaded = extension_loaded($ext);
    $display = $label ?: $ext;
    return row($display, $loaded ? 'Aktif' : 'Tidak aktif', $loaded ? 'ok' : 'warning', $note);
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
// 1. Informasi Hosting & Server
// ----------------------------------------------------------
$s1 = [];

$server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Tidak diketahui';
$s1[] = row('Web Server', $server_software, 'info');

$server_ip   = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['LOCAL_ADDR'] ?? 'N/A');
$client_ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'N/A');
$s1[] = row('Server IP', $server_ip, 'info');
$s1[] = row('Client IP', $client_ip, 'info');
$s1[] = row('Hostname', gethostname() ?: 'N/A', 'info');
$s1[] = row('Domain', $_SERVER['HTTP_HOST'] ?? 'N/A', 'info');
$s1[] = row('Document Root', $_SERVER['DOCUMENT_ROOT'] ?? 'N/A', 'info');
$s1[] = row('Script Path', __FILE__, 'info');
$s1[] = row('OS', PHP_OS_FAMILY . ' (' . php_uname('r') . ')', 'info');
$s1[] = row('Server Time', date('d M Y H:i:s T'), 'info');

// cPanel / Plesk / DirectAdmin detection
$panel = 'Tidak terdeteksi';
if (file_exists('/usr/local/cpanel/cpanel'))       $panel = 'cPanel';
elseif (file_exists('/usr/local/psa/version'))     $panel = 'Plesk';
elseif (file_exists('/usr/local/directadmin/directadmin')) $panel = 'DirectAdmin';
elseif (file_exists('/usr/local/cwpsrv/htdocs'))   $panel = 'CWP (CentOS Web Panel)';
elseif (file_exists('/usr/local/hestia/bin/hestia')) $panel = 'HestiaCP';
elseif (file_exists('/usr/local/vesta/bin/vesta'))  $panel = 'VestaCP';
$s1[] = row('Control Panel', $panel, $panel !== 'Tidak terdeteksi' ? 'info' : 'info');

// HTTPS
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? 80) == 443
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$s1[] = row('HTTPS / SSL', $is_https ? 'Aktif' : 'Tidak aktif', $is_https ? 'ok' : 'warning', !$is_https ? 'Aktifkan SSL/HTTPS untuk keamanan' : '');

// Disk usage
$disk_free  = @disk_free_space($wp_root);
$disk_total = @disk_total_space($wp_root);
if ($disk_free !== false && $disk_total !== false) {
    $disk_used    = $disk_total - $disk_free;
    $disk_pct     = $disk_total > 0 ? round(($disk_used / $disk_total) * 100, 1) : 0;
    $disk_status  = $disk_pct >= 90 ? 'error' : ($disk_pct >= 75 ? 'warning' : 'ok');
    $s1[] = row('Disk Total', format_bytes((int)$disk_total), 'info');
    $s1[] = row('Disk Terpakai', format_bytes((int)$disk_used) . ' (' . $disk_pct . '%)', $disk_status, $disk_pct >= 75 ? 'Disk hampir penuh — segera bersihkan atau upgrade paket' : '');
    $s1[] = row('Disk Tersisa', format_bytes((int)$disk_free), $disk_status === 'ok' ? 'ok' : $disk_status);
} else {
    $s1[] = row('Disk Usage', 'Tidak dapat membaca informasi disk', 'warning');
}

$sections['Informasi Hosting & Server'] = ['icon' => '🖥️', 'rows' => $s1];

// ----------------------------------------------------------
// 2. PHP Environment
// ----------------------------------------------------------
$s2 = [];

$php_version = PHP_VERSION;
$php_status  = version_compare($php_version, '8.1', '>=') ? 'ok'
    : (version_compare($php_version, '8.0', '>=') ? 'ok'
    : (version_compare($php_version, '7.4', '>=') ? 'warning' : 'error'));
$s2[] = row('PHP Version', $php_version, $php_status,
    $php_status === 'warning' ? 'Disarankan PHP 8.0+ untuk WordPress terbaru' :
    ($php_status === 'error' ? 'PHP versi ini sudah End-of-Life, segera upgrade' : ''));

$s2[] = row('PHP SAPI', php_sapi_name() ?: 'N/A', 'info');
$s2[] = row('PHP Binary', PHP_BINARY ?: 'N/A', 'info');

$memory_limit = ini_get('memory_limit');
$memory_bytes = return_bytes($memory_limit);
$mem_status   = ($memory_bytes >= 256 * 1024 * 1024) ? 'ok'
    : (($memory_bytes >= 128 * 1024 * 1024) ? 'warning' : 'error');
$s2[] = row('Memory Limit', $memory_limit, $mem_status,
    $mem_status !== 'ok' ? 'WordPress merekomendasikan minimal 256M' : '');

$max_exec    = ini_get('max_execution_time');
$exec_status = ($max_exec == 0 || $max_exec >= 60) ? 'ok' : 'warning';
$s2[] = row('Max Execution Time', $max_exec . ' detik', $exec_status,
    $exec_status === 'warning' ? 'Disarankan minimal 60 detik untuk WordPress' : '');

$upload_max = ini_get('upload_max_filesize');
$post_max   = ini_get('post_max_size');
$s2[] = row('Upload Max Filesize', $upload_max, 'info');
$s2[] = row('Post Max Size', $post_max, 'info');

$max_input_vars = ini_get('max_input_vars');
$input_status   = ($max_input_vars >= 3000) ? 'ok' : 'warning';
$s2[] = row('Max Input Vars', $max_input_vars ?: 'N/A', $input_status,
    $input_status === 'warning' ? 'Disarankan minimal 3000 untuk theme/plugin kompleks' : '');

$s2[] = row('Display Errors', ini_get('display_errors') ? 'On' : 'Off',
    ini_get('display_errors') ? 'warning' : 'ok',
    ini_get('display_errors') ? 'Nonaktifkan display_errors di production' : '');

$s2[] = row('Error Reporting', ini_get('error_reporting'), 'info');
$s2[] = row('Default Timezone', ini_get('date.timezone') ?: 'Tidak diset', 'info');
$s2[] = row('Disabled Functions', ini_get('disable_functions') ?: 'Tidak ada', 'info');

$sections['PHP Environment'] = ['icon' => '🐘', 'rows' => $s2];

// ----------------------------------------------------------
// 3. PHP Extensions
// ----------------------------------------------------------
$s3 = [];

$required_exts = [
    'curl'      => ['label' => 'cURL', 'note' => 'Diperlukan untuk HTTP requests, update, dan API'],
    'gd'        => ['label' => 'GD / Imagick', 'note' => 'Diperlukan untuk resize gambar'],
    'mbstring'  => ['label' => 'mbstring', 'note' => 'Diperlukan untuk multi-byte string'],
    'mysqli'    => ['label' => 'MySQLi', 'note' => 'Diperlukan untuk koneksi database WordPress'],
    'openssl'   => ['label' => 'OpenSSL', 'note' => 'Diperlukan untuk HTTPS dan enkripsi'],
    'zip'       => ['label' => 'ZIP', 'note' => 'Diperlukan untuk install/update plugin & theme'],
    'xml'       => ['label' => 'XML', 'note' => 'Diperlukan untuk feed dan sitemap'],
    'json'      => ['label' => 'JSON', 'note' => 'Diperlukan untuk REST API'],
    'intl'      => ['label' => 'Intl', 'note' => 'Diperlukan untuk internasionalisasi'],
    'exif'      => ['label' => 'EXIF', 'note' => 'Diperlukan untuk metadata gambar'],
    'fileinfo'  => ['label' => 'Fileinfo', 'note' => 'Diperlukan untuk deteksi tipe file'],
    'dom'       => ['label' => 'DOM', 'note' => 'Diperlukan untuk parsing HTML/XML'],
    'simplexml' => ['label' => 'SimpleXML', 'note' => 'Diperlukan untuk parsing XML'],
    'pdo'       => ['label' => 'PDO', 'note' => 'Diperlukan untuk beberapa plugin database'],
    'pdo_mysql' => ['label' => 'PDO MySQL', 'note' => ''],
    'soap'      => ['label' => 'SOAP', 'note' => 'Diperlukan untuk beberapa integrasi payment'],
    'iconv'     => ['label' => 'iconv', 'note' => 'Diperlukan untuk konversi karakter'],
    'bcmath'    => ['label' => 'BCMath', 'note' => 'Diperlukan untuk kalkulasi presisi tinggi'],
];

foreach ($required_exts as $ext => $info) {
    $loaded = extension_loaded($ext);
    // GD fallback: cek imagick juga
    if ($ext === 'gd' && !$loaded) {
        $loaded = extension_loaded('imagick');
        $info['label'] = 'GD / Imagick';
    }
    $s3[] = row($info['label'], $loaded ? 'Aktif' : 'Tidak aktif',
        $loaded ? 'ok' : 'warning', $loaded ? '' : $info['note']);
}

// OPcache
$opcache_enabled = function_exists('opcache_get_status') && opcache_get_status(false) !== false;
$s3[] = row('PHP OPcache', $opcache_enabled ? 'Aktif' : 'Tidak aktif',
    $opcache_enabled ? 'ok' : 'warning',
    !$opcache_enabled ? 'Aktifkan OPcache untuk performa PHP lebih baik' : '');

$sections['PHP Extensions'] = ['icon' => '🔌', 'rows' => $s3];

// ----------------------------------------------------------
// 4. WP-CLI Detection
// ----------------------------------------------------------
$s4 = [];
if ($wp_cli_available) {
    $s4[] = row('WP-CLI Tersedia', 'Ya — ' . $wp_cli_path, 'ok');
    $s4[] = row('WP-CLI Versi', $wp_cli_version, 'info');
} else {
    $s4[] = row('WP-CLI Tersedia', 'Tidak ditemukan', 'error',
        'Install WP-CLI: https://wp-cli.org/#installing');
    $s4[] = row('shell_exec', function_exists('shell_exec') ? 'Tersedia' : 'Dinonaktifkan',
        function_exists('shell_exec') ? 'ok' : 'error');
    $s4[] = row('proc_open', function_exists('proc_open') ? 'Tersedia' : 'Dinonaktifkan',
        function_exists('proc_open') ? 'ok' : 'error');
}
$sections['WP-CLI'] = ['icon' => '🔧', 'rows' => $s4];

// ----------------------------------------------------------
// 5. WordPress Core
// ----------------------------------------------------------
$s5 = [];

if ($wp_cli_available) {
    $installed = run_wp_cli('core is-installed');
    $s5[] = row('WP Terinstall', $installed['success'] ? 'Ya' : 'Tidak / Error: ' . $installed['output'],
        $installed['success'] ? 'ok' : 'error');

    $core_ver = run_wp_cli('core version');
    $s5[] = row('WordPress Version', $core_ver['success'] ? $core_ver['output'] : 'Gagal: ' . $core_ver['output'],
        $core_ver['success'] ? 'ok' : 'error');

    $blogname = run_wp_cli('option get blogname');
    $s5[] = row('Blog Name', $blogname['success'] ? $blogname['output'] : 'Gagal',
        $blogname['success'] ? 'info' : 'warning');

    $siteurl = run_wp_cli('option get siteurl');
    $s5[] = row('Site URL', $siteurl['success'] ? $siteurl['output'] : 'Gagal',
        $siteurl['success'] ? 'info' : 'warning');

    $homeurl = run_wp_cli('option get home');
    $s5[] = row('Home URL', $homeurl['success'] ? $homeurl['output'] : 'Gagal',
        $homeurl['success'] ? 'info' : 'warning');

    $debug = run_wp_cli('config get WP_DEBUG');
    $debug_val    = $debug['success'] ? trim($debug['output']) : 'Gagal';
    $debug_status = ($debug_val === '1' || strtolower($debug_val) === 'true') ? 'warning' : 'ok';
    $s5[] = row('WP_DEBUG', $debug_val, $debug_status,
        $debug_status === 'warning' ? 'Debug aktif — nonaktifkan di production' : '');

    $debug_log = run_wp_cli('config get WP_DEBUG_LOG');
    $s5[] = row('WP_DEBUG_LOG', $debug_log['success'] ? $debug_log['output'] : 'Tidak diset', 'info');

    $wplang = run_wp_cli('config get WPLANG');
    $s5[] = row('WP Language', ($wplang['success'] && !empty($wplang['output'])) ? $wplang['output'] : 'en_US (default)', 'info');

    // wp core update-check
    $update_check = run_wp_cli('core check-update --format=json', true);
    if ($update_check['success'] && is_array($update_check['output']) && count($update_check['output']) > 0) {
        $latest = $update_check['output'][0]['version'] ?? 'N/A';
        $s5[] = row('Update Tersedia', 'Ya — versi ' . $latest, 'warning', 'Segera update WordPress ke versi terbaru');
    } elseif ($update_check['success']) {
        $s5[] = row('Update Tersedia', 'Tidak — sudah versi terbaru', 'ok');
    }

} else {
    $wp_config_path = $wp_root . '/wp-config.php';
    if (file_exists($wp_config_path)) {
        $s5[] = row('wp-config.php', 'Ditemukan', 'ok');
        // Baca versi dari wp-includes/version.php
        $ver_file = $wp_root . '/wp-includes/version.php';
        if (file_exists($ver_file)) {
            $wp_version = '';
            include $ver_file;
            $s5[] = row('WordPress Version', $wp_version ?: 'Tidak dapat dibaca', $wp_version ? 'info' : 'warning');
        }
    } else {
        $s5[] = row('wp-config.php', 'Tidak ditemukan di ' . $wp_root, 'error',
            'Pastikan file ini diletakkan di root WordPress');
    }
    $s5[] = row('Detail WordPress', 'WP-CLI diperlukan untuk pemeriksaan detail', 'warning');
}

$sections['WordPress Core'] = ['icon' => '🏠', 'rows' => $s5];

// ----------------------------------------------------------
// 6. Database
// ----------------------------------------------------------
$s6 = [];

if ($wp_cli_available) {
    $db_check = run_wp_cli('db check');
    $db_ok    = $db_check['success']
        && (stripos($db_check['output'], 'success') !== false
            || stripos($db_check['output'], 'OK') !== false
            || empty($db_check['output']));
    $s6[] = row('Koneksi Database', $db_ok ? 'OK' : ($db_check['output'] ?: 'Gagal'),
        $db_ok ? 'ok' : 'error');

    $db_size = run_wp_cli('db size --size_format=mb');
    $s6[] = row('Ukuran Database', $db_size['success'] ? $db_size['output'] . ' MB' : 'Gagal',
        $db_size['success'] ? 'info' : 'warning');

    $db_tables = run_wp_cli('db tables');
    if ($db_tables['success'] && !empty($db_tables['output'])) {
        $tables      = array_filter(explode("\n", $db_tables['output']));
        $table_count = count($tables);
        $s6[] = row('Jumlah Tabel', (string)$table_count . ' tabel', $table_count > 0 ? 'ok' : 'warning');
    } else {
        $s6[] = row('Tabel Database', 'Gagal: ' . $db_tables['output'], 'error');
    }

    $db_charset = run_wp_cli('db query "SELECT @@character_set_database, @@collation_database" --skip-column-names');
    if ($db_charset['success']) {
        $s6[] = row('Charset / Collation', str_replace("\t", ' / ', $db_charset['output']), 'info');
    }

    // MySQL version
    $mysql_ver = run_wp_cli('db query "SELECT VERSION()" --skip-column-names');
    if ($mysql_ver['success']) {
        $s6[] = row('MySQL / MariaDB Version', $mysql_ver['output'], 'info');
    }

} else {
    // Fallback: baca wp-config.php
    $wp_config_path = $wp_root . '/wp-config.php';
    if (file_exists($wp_config_path)) {
        $config_content = @file_get_contents($wp_config_path);
        if ($config_content) {
            preg_match("/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $config_content, $host_m);
            preg_match("/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $config_content, $name_m);
            preg_match("/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $config_content, $user_m);
            if (!empty($host_m[1])) $s6[] = row('DB Host', $host_m[1], 'info');
            if (!empty($name_m[1])) $s6[] = row('DB Name', $name_m[1], 'info');
            if (!empty($user_m[1])) $s6[] = row('DB User', $user_m[1], 'info');

            // Coba koneksi manual
            if (!empty($host_m[1]) && !empty($name_m[1]) && !empty($user_m[1])) {
                preg_match("/define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]*)['\"]\\s*\)/", $config_content, $pass_m);
                $db_pass = $pass_m[1] ?? '';
                $conn = @mysqli_connect($host_m[1], $user_m[1], $db_pass, $name_m[1]);
                if ($conn) {
                    $s6[] = row('Koneksi Database', 'OK (koneksi manual berhasil)', 'ok');
                    $s6[] = row('MySQL Version', mysqli_get_server_info($conn), 'info');
                    mysqli_close($conn);
                } else {
                    $s6[] = row('Koneksi Database', 'Gagal: ' . mysqli_connect_error(), 'error');
                }
            }
        }
    } else {
        $s6[] = row('Database Check', 'wp-config.php tidak ditemukan', 'error');
    }
}

$sections['Database'] = ['icon' => '🗄️', 'rows' => $s6];

// ----------------------------------------------------------
// 7. Plugin & Theme
// ----------------------------------------------------------
$s7 = [];

if ($wp_cli_available) {
    $plugin_count = run_wp_cli('plugin list --format=count');
    $s7[] = row('Total Plugin', $plugin_count['success'] ? $plugin_count['output'] : 'Gagal',
        $plugin_count['success'] ? 'info' : 'warning');

    $active_plugins = run_wp_cli('plugin list --status=active --fields=name,version,update --format=json', true);
    if ($active_plugins['success'] && is_array($active_plugins['output'])) {
        $plugins = $active_plugins['output'];
        $s7[] = row('Plugin Aktif', count($plugins) . ' plugin', 'ok');
        foreach ($plugins as $plugin) {
            $name    = $plugin['name'] ?? 'Unknown';
            $version = $plugin['version'] ?? 'N/A';
            $update  = $plugin['update'] ?? 'none';
            $p_status = ($update === 'available') ? 'warning' : 'ok';
            $p_note   = ($update === 'available') ? 'Update tersedia' : '';
            $s7[] = row('  ↳ ' . $name, 'v' . $version, $p_status, $p_note);
        }
    } else {
        $s7[] = row('Plugin Aktif', 'Gagal: ' . $active_plugins['raw'], 'warning');
    }

    $inactive_count = run_wp_cli('plugin list --status=inactive --format=count');
    $s7[] = row('Plugin Tidak Aktif', $inactive_count['success'] ? $inactive_count['output'] : 'Gagal', 'info');

    $mu_count = run_wp_cli('plugin list --status=must-use --format=count');
    if ($mu_count['success']) {
        $s7[] = row('Must-Use Plugin', $mu_count['output'], 'info');
    }

    $active_theme = run_wp_cli('theme list --status=active --fields=name,version,update --format=json', true);
    if ($active_theme['success'] && is_array($active_theme['output'])) {
        foreach ($active_theme['output'] as $theme) {
            $t_name   = $theme['name'] ?? 'Unknown';
            $t_ver    = $theme['version'] ?? 'N/A';
            $t_update = $theme['update'] ?? 'none';
            $t_status = ($t_update === 'available') ? 'warning' : 'ok';
            $t_note   = ($t_update === 'available') ? 'Update tersedia' : '';
            $s7[] = row('Theme Aktif', $t_name . ' v' . $t_ver, $t_status, $t_note);
        }
    } else {
        $s7[] = row('Theme Aktif', 'Gagal: ' . $active_theme['raw'], 'warning');
    }

    $theme_count = run_wp_cli('theme list --format=count');
    $s7[] = row('Total Theme', $theme_count['success'] ? $theme_count['output'] : 'Gagal', 'info');

} else {
    // Fallback: scan direktori
    $plugins_dir = $wp_root . '/wp-content/plugins';
    if (is_dir($plugins_dir)) {
        $plugin_dirs = array_filter(glob($plugins_dir . '/*'), 'is_dir');
        $s7[] = row('Plugin (direktori)', count($plugin_dirs) . ' folder ditemukan', 'info',
            'WP-CLI diperlukan untuk status aktif/nonaktif');
    }
    $themes_dir = $wp_root . '/wp-content/themes';
    if (is_dir($themes_dir)) {
        $theme_dirs = array_filter(glob($themes_dir . '/*'), 'is_dir');
        $s7[] = row('Theme (direktori)', count($theme_dirs) . ' folder ditemukan', 'info');
    }
    $s7[] = row('Detail Plugin & Theme', 'WP-CLI diperlukan untuk pemeriksaan lengkap', 'warning');
}

$sections['Plugin & Theme'] = ['icon' => '🧩', 'rows' => $s7];

// ----------------------------------------------------------
// 8. Performance
// ----------------------------------------------------------
$s8 = [];

if ($wp_cli_available) {
    $cache_type   = run_wp_cli('cache type');
    $cache_val    = $cache_type['success'] ? $cache_type['output'] : 'Gagal';
    $cache_status = (stripos($cache_val, 'redis') !== false || stripos($cache_val, 'memcache') !== false) ? 'ok' : 'warning';
    $s8[] = row('Object Cache', $cache_val, $cache_status,
        $cache_status === 'warning' ? 'Pertimbangkan Redis/Memcached untuk performa lebih baik' : '');

    $cron_events = run_wp_cli('cron event list --fields=hook,next_run_relative --format=json', true);
    if ($cron_events['success'] && is_array($cron_events['output'])) {
        $events = $cron_events['output'];
        $s8[] = row('WP-Cron Events', count($events) . ' event terjadwal',
            count($events) > 50 ? 'warning' : 'ok',
            count($events) > 50 ? 'Terlalu banyak cron event dapat mempengaruhi performa' : '');
        $shown = array_slice($events, 0, 10);
        foreach ($shown as $event) {
            $hook     = $event['hook'] ?? 'Unknown';
            $next_run = $event['next_run_relative'] ?? 'N/A';
            $s8[] = row('  ↳ ' . $hook, $next_run, 'info');
        }
        if (count($events) > 10) {
            $s8[] = row('  ...', '+ ' . (count($events) - 10) . ' event lainnya', 'info');
        }
    } else {
        $s8[] = row('WP-Cron Events', 'Gagal: ' . $cron_events['raw'], 'warning');
    }

    $transients = run_wp_cli('transient list --format=count');
    if ($transients['success']) {
        $t_count  = (int)$transients['output'];
        $t_status = $t_count > 1000 ? 'warning' : 'ok';
        $s8[] = row('Transients', $t_count . ' transient', $t_status,
            $t_status === 'warning' ? 'Banyak transient dapat memperlambat database' : '');
    }
} else {
    $s8[] = row('Performance Check', 'WP-CLI diperlukan untuk pemeriksaan performa', 'warning');
}

$s8[] = row('PHP OPcache', $opcache_enabled ? 'Aktif' : 'Tidak aktif',
    $opcache_enabled ? 'ok' : 'warning',
    !$opcache_enabled ? 'Aktifkan OPcache untuk performa PHP lebih baik' : '');

$sections['Performance'] = ['icon' => '⚡', 'rows' => $s8];

// ----------------------------------------------------------
// 9. Email / SMTP
// ----------------------------------------------------------
$s9 = [];

// Cek fungsi mail()
$mail_enabled = function_exists('mail');
$s9[] = row('PHP mail()', $mail_enabled ? 'Tersedia' : 'Dinonaktifkan',
    $mail_enabled ? 'ok' : 'warning',
    !$mail_enabled ? 'Fungsi mail() dinonaktifkan — gunakan plugin SMTP' : '');

// Cek sendmail path
$sendmail_path = ini_get('sendmail_path');
$s9[] = row('Sendmail Path', $sendmail_path ?: 'Tidak diset', $sendmail_path ? 'info' : 'warning');

// Cek SMTP plugin umum (jika WP-CLI tersedia)
if ($wp_cli_available) {
    $smtp_plugins = [
        'wp-mail-smtp/wp_mail_smtp.php'   => 'WP Mail SMTP',
        'easy-wp-smtp/easy-wp-smtp.php'   => 'Easy WP SMTP',
        'post-smtp/postman-smtp.php'       => 'Post SMTP',
        'fluent-smtp/fluent-smtp.php'      => 'FluentSMTP',
        'smtp-mailer/main.php'             => 'SMTP Mailer',
    ];
    $smtp_found = false;
    foreach ($smtp_plugins as $slug => $name) {
        $check = run_wp_cli('plugin is-active ' . escapeshellarg(dirname($slug)));
        if ($check['success']) {
            $s9[] = row('SMTP Plugin Aktif', $name, 'ok');
            $smtp_found = true;
            break;
        }
    }
    if (!$smtp_found) {
        $s9[] = row('SMTP Plugin', 'Tidak ada SMTP plugin aktif', 'warning',
            'Disarankan menggunakan plugin SMTP untuk pengiriman email yang andal');
    }

    // Cek wp_mail dari option
    $admin_email = run_wp_cli('option get admin_email');
    $s9[] = row('Admin Email', $admin_email['success'] ? $admin_email['output'] : 'Gagal',
        $admin_email['success'] ? 'info' : 'warning');
}

// Cek port SMTP umum (25, 465, 587)
$smtp_ports = [25 => 'SMTP', 465 => 'SMTPS', 587 => 'SMTP TLS'];
foreach ($smtp_ports as $port => $proto) {
    $conn = @fsockopen('localhost', $port, $errno, $errstr, 3);
    if ($conn) {
        fclose($conn);
        $s9[] = row('Port ' . $port . ' (' . $proto . ')', 'Terbuka (localhost)', 'info');
    }
}

$sections['Email / SMTP'] = ['icon' => '📧', 'rows' => $s9];

// ----------------------------------------------------------
// 10. Keamanan
// ----------------------------------------------------------
$s10 = [];

// wp-config.php permissions
$wp_config_path = $wp_root . '/wp-config.php';
if (file_exists($wp_config_path)) {
    $perms     = substr(sprintf('%o', fileperms($wp_config_path)), -4);
    $perm_int  = octdec($perms);
    $perm_status = ($perm_int <= octdec('0640')) ? 'ok' : 'warning';
    $s10[] = row('Permission wp-config.php', $perms, $perm_status,
        $perm_status === 'warning' ? 'Disarankan 400, 440, atau 600' : '');
} else {
    $s10[] = row('wp-config.php', 'Tidak ditemukan di ' . $wp_root, 'error');
}

// wp-content permissions
$wp_content_path = $wp_root . '/wp-content';
if (is_dir($wp_content_path)) {
    $perms_content       = substr(sprintf('%o', fileperms($wp_content_path)), -4);
    $perm_content_int    = octdec($perms_content);
    $perm_content_status = ($perm_content_int <= octdec('0755')) ? 'ok' : 'warning';
    $s10[] = row('Permission wp-content/', $perms_content, $perm_content_status,
        $perm_content_status === 'warning' ? 'Disarankan 755 atau lebih ketat' : '');
}

// uploads permissions
$uploads_path = $wp_root . '/wp-content/uploads';
if (is_dir($uploads_path)) {
    $perms_uploads = substr(sprintf('%o', fileperms($uploads_path)), -4);
    $s10[] = row('Permission wp-content/uploads/', $perms_uploads, 'info');
}

if ($wp_cli_available) {
    $file_edit = run_wp_cli('config get DISALLOW_FILE_EDIT');
    $fe_val    = $file_edit['success'] ? trim($file_edit['output']) : null;
    if ($fe_val === null || $fe_val === '' || $fe_val === 'false' || $fe_val === '0') {
        $s10[] = row('DISALLOW_FILE_EDIT', $fe_val ?? 'Tidak diset', 'warning',
            'Tambahkan define("DISALLOW_FILE_EDIT", true) di wp-config.php');
    } else {
        $s10[] = row('DISALLOW_FILE_EDIT', $fe_val, 'ok');
    }

    $file_mods = run_wp_cli('config get DISALLOW_FILE_MODS');
    $fm_val    = $file_mods['success'] ? trim($file_mods['output']) : 'Tidak diset';
    $s10[] = row('DISALLOW_FILE_MODS', $fm_val,
        ($fm_val === '1' || $fm_val === 'true') ? 'ok' : 'info');

    $ssl_admin = run_wp_cli('config get FORCE_SSL_ADMIN');
    $ssl_val   = $ssl_admin['success'] ? trim($ssl_admin['output']) : 'Tidak diset';
    $ssl_status = ($ssl_val === '1' || $ssl_val === 'true') ? 'ok' : 'warning';
    $s10[] = row('FORCE_SSL_ADMIN', $ssl_val, $ssl_status,
        $ssl_status === 'warning' ? 'Aktifkan FORCE_SSL_ADMIN untuk keamanan login' : '');
}

// .htaccess
$htaccess_path = $wp_root . '/.htaccess';
$s10[] = row('.htaccess', file_exists($htaccess_path) ? 'Ada' : 'Tidak ditemukan',
    file_exists($htaccess_path) ? 'ok' : 'warning');

// index.php (root)
$index_path = $wp_root . '/index.php';
if (file_exists($index_path)) {
    $index_perms     = substr(sprintf('%o', fileperms($index_path)), -4);
    $index_perm_int  = octdec($index_perms);
    $index_perm_ok   = ($index_perm_int <= octdec('0644'));
    $s10[] = row('index.php (root)', 'Ada — permission: ' . $index_perms,
        $index_perm_ok ? 'ok' : 'warning',
        !$index_perm_ok ? 'Permission terlalu longgar, disarankan 644' : '');

    // Cek isi index.php — deteksi injeksi/malware sederhana
    $index_content = @file_get_contents($index_path);
    if ($index_content !== false) {
        $index_size = strlen($index_content);
        // WordPress default index.php sangat kecil (~20 baris)
        $suspicious_patterns = [
            'eval(base64_decode'  => 'eval+base64_decode (indikasi malware)',
            'eval(gzinflate'      => 'eval+gzinflate (indikasi malware)',
            'eval(str_rot13'      => 'eval+str_rot13 (indikasi malware)',
            'base64_decode(str_replace' => 'base64_decode obfuscation',
            '$_POST[' => 'Akses $_POST langsung di index.php',
            '$_GET['  => 'Akses $_GET langsung di index.php',
            'preg_replace.*\/e'   => 'preg_replace /e modifier (code execution)',
        ];
        $found_suspicious = [];
        foreach ($suspicious_patterns as $pattern => $desc) {
            if (stripos($index_content, $pattern) !== false) {
                $found_suspicious[] = $desc;
            }
        }
        if (!empty($found_suspicious)) {
            $s10[] = row('index.php — Konten', 'Mencurigakan: ' . implode('; ', $found_suspicious), 'error',
                'Periksa isi index.php — kemungkinan injeksi malware!');
        } elseif ($index_size > 5000) {
            $s10[] = row('index.php — Ukuran', format_bytes($index_size) . ' (tidak normal)', 'warning',
                'index.php WordPress standar sangat kecil (~20 baris). Periksa isinya.');
        } else {
            $s10[] = row('index.php — Konten', 'Normal (' . format_bytes($index_size) . ')', 'ok');
        }
    }
} else {
    $s10[] = row('index.php (root)', 'Tidak ditemukan!', 'error',
        'File index.php WordPress harus ada di root. Kemungkinan terhapus atau salah direktori.');
}

// index.php di wp-content/ (WordPress "silence is golden" file)
$wpcontent_index = $wp_root . '/wp-content/index.php';
$s10[] = row('wp-content/index.php', file_exists($wpcontent_index) ? 'Ada' : 'Tidak ada',
    file_exists($wpcontent_index) ? 'ok' : 'warning',
    !file_exists($wpcontent_index) ? 'File ini mencegah directory listing di wp-content/' : '');

// index.php di wp-includes/ (WordPress "silence is golden" file)
$wpincludes_index = $wp_root . '/wp-includes/index.php';
$s10[] = row('wp-includes/index.php', file_exists($wpincludes_index) ? 'Ada' : 'Tidak ada',
    file_exists($wpincludes_index) ? 'ok' : 'warning',
    !file_exists($wpincludes_index) ? 'File ini mencegah directory listing di wp-includes/' : '');

// xmlrpc.php
$xmlrpc_path = $wp_root . '/xmlrpc.php';
$s10[] = row('xmlrpc.php', file_exists($xmlrpc_path) ? 'Ada' : 'Tidak ada',
    file_exists($xmlrpc_path) ? 'warning' : 'ok',
    file_exists($xmlrpc_path) ? 'Pertimbangkan menonaktifkan jika tidak digunakan' : '');

// readme.html
$readme_path = $wp_root . '/readme.html';
$s10[] = row('readme.html', file_exists($readme_path) ? 'Ada (sebaiknya dihapus)' : 'Tidak ada',
    file_exists($readme_path) ? 'warning' : 'ok');

$s10[] = row('HTTPS / SSL', $is_https ? 'Aktif' : 'Tidak aktif', $is_https ? 'ok' : 'warning',
    !$is_https ? 'Gunakan HTTPS untuk keamanan' : '');

$sections['Keamanan'] = ['icon' => '🔒', 'rows' => $s10];

// ----------------------------------------------------------
// 11. Error Log
// ----------------------------------------------------------
$s11 = [];

$error_log_path = ini_get('error_log');
$log_sources    = array_filter([
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
        $s11[] = row('Error Log Ditemukan', $log_path . ' (' . format_bytes((int)$file_size) . ')', 'info');

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
            $s11[] = row('Status Log', $has_error ? 'Ada Fatal/Parse Error' : ($has_warning ? 'Ada Warning/Notice' : 'Bersih'), $log_status);
        }
        break;
    }
}

if (!$log_found) {
    $s11[] = row('Error Log', 'Tidak ditemukan atau tidak dapat dibaca', 'info',
        'Aktifkan WP_DEBUG_LOG di wp-config.php untuk mencatat error');
}

$sections['Error Log'] = ['icon' => '📋', 'rows' => $s11];

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
    <title>WordPress Hosting Diagnostic Tool</title>
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
            max-width: 1140px;
            margin: 0 auto;
            padding: 24px 16px 56px;
        }

        /* ===== HEADER ===== */
        .header {
            background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%);
            color: #fff;
            border-radius: 14px;
            padding: 32px 36px;
            margin-bottom: 20px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.25);
        }
        .header h1 {
            font-size: 1.55em;
            font-weight: 700;
            letter-spacing: -0.3px;
            margin-bottom: 4px;
        }
        .header h1 span { color: #56ccf2; }
        .header .subtitle { color: #adb5bd; font-size: 0.88em; }
        .header .meta {
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 0.8em;
            color: #ced4da;
        }
        .header .meta span { display: flex; align-items: center; gap: 5px; }

        /* ===== BANNERS ===== */
        .warning-banner {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-left: 5px solid #ffc107;
            border-radius: 8px;
            padding: 14px 20px;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .warning-banner .icon { font-size: 1.4em; flex-shrink: 0; }
        .warning-banner strong { color: #856404; display: block; margin-bottom: 2px; }
        .warning-banner p { color: #856404; font-size: 0.88em; }

        .cli-banner {
            border-radius: 8px;
            padding: 11px 18px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 0.9em;
        }
        .cli-banner.available   { background: #d4edda; border: 1px solid #28a745; color: #155724; }
        .cli-banner.unavailable { background: #f8d7da; border: 1px solid #dc3545; color: #721c24; }
        .cli-banner .icon { font-size: 1.2em; }

        /* ===== SUMMARY ===== */
        .summary {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            padding: 18px 24px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            align-items: center;
        }
        .summary h3 { font-size: 0.95em; color: #495057; margin-right: auto; }
        .summary-item { display: flex; align-items: center; gap: 7px; font-size: 0.88em; }
        .summary-dot { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }
        .dot-ok      { background: #28a745; }
        .dot-warning { background: #ffc107; }
        .dot-error   { background: #dc3545; }
        .dot-info    { background: #17a2b8; }

        /* ===== SECTION CARDS ===== */
        .section-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            margin-bottom: 18px;
            overflow: hidden;
        }

        table { width: 100%; border-collapse: collapse; }
        table th { text-align: left; }
        table td { vertical-align: top; }
        table td:first-child { width: 28%; min-width: 170px; }
        table td:last-child  { width: 110px; text-align: center; }

        /* ===== LOG VIEWER ===== */
        .log-container {
            background: #1e1e1e;
            color: #d4d4d4;
            border-radius: 0 0 10px 10px;
            padding: 16px 20px;
            overflow-x: auto;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.77em;
            line-height: 1.65;
            max-height: 380px;
            overflow-y: auto;
        }
        .log-container .log-line { display: block; padding: 1px 0; }
        .log-line.error-line   { color: #f48771; }
        .log-line.warning-line { color: #dcdcaa; }
        .log-line.notice-line  { color: #9cdcfe; }

        /* ===== FOOTER ===== */
        .footer {
            text-align: center;
            color: #6c757d;
            font-size: 0.8em;
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #dee2e6;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 640px) {
            table td:first-child { width: 42%; }
            .header { padding: 20px; }
            .header h1 { font-size: 1.15em; }
        }

        /* ===== PRINT ===== */
        @media print {
            body { background: #fff; }
            .wrapper { max-width: 100%; padding: 0; }
            .warning-banner { display: none; }
        }
    </style>
</head>
<body>
<div class="wrapper">

    <!-- HEADER -->
    <div class="header">
        <h1>🩺 WordPress <span>Hosting Diagnostic Tool</span></h1>
        <p class="subtitle">Laporan diagnosa lengkap untuk Technical Support Hosting — Server, PHP, Database, WordPress, Email &amp; Keamanan</p>
        <div class="meta">
            <span>📅 <?= date('d M Y, H:i:s T') ?></span>
            <span>🌐 <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') ?></span>
            <span>📁 <?= htmlspecialchars($wp_root) ?></span>
            <span>⏱️ <?= $exec_time ?> ms</span>
        </div>
    </div>

    <!-- WARNING BANNER -->
    <div class="warning-banner">
        <div class="icon">⚠️</div>
        <div>
            <strong>Peringatan Keamanan — Untuk Technical Support</strong>
            <p>File <code>wp-diagnose.php</code> ini mengekspos informasi sensitif server. <strong>Hapus segera setelah diagnosa selesai.</strong> Jangan biarkan file ini dapat diakses publik.</p>
        </div>
    </div>

    <!-- WP-CLI STATUS -->
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

    <!-- SUMMARY -->
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
        <div class="summary-item"><span class="summary-dot dot-ok"></span> <strong><?= $total_ok ?></strong>&nbsp;OK</div>
        <div class="summary-item"><span class="summary-dot dot-warning"></span> <strong><?= $total_warning ?></strong>&nbsp;Warning</div>
        <div class="summary-item"><span class="summary-dot dot-error"></span> <strong><?= $total_error ?></strong>&nbsp;Error</div>
        <div class="summary-item"><span class="summary-dot dot-info"></span> <strong><?= $total_info ?></strong>&nbsp;Info</div>
    </div>

    <!-- SECTIONS -->
    <?php foreach ($sections as $section_name => $section_data): ?>
    <div class="section-card">
        <table>
            <thead>
                <?= section_header($section_name, $section_data['icon']) ?>
                <tr style="background:#f8f9fa;">
                    <th style="padding:8px 14px;border-bottom:2px solid #dee2e6;color:#495057;font-size:0.82em;text-transform:uppercase;letter-spacing:0.5px;">Pemeriksaan</th>
                    <th style="padding:8px 14px;border-bottom:2px solid #dee2e6;color:#495057;font-size:0.82em;text-transform:uppercase;letter-spacing:0.5px;">Nilai / Keterangan</th>
                    <th style="padding:8px 14px;border-bottom:2px solid #dee2e6;color:#495057;font-size:0.82em;text-transform:uppercase;letter-spacing:0.5px;text-align:center;">Status</th>
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

    <!-- ERROR LOG CONTENT -->
    <?php
    $error_log_path_display = ini_get('error_log');
    $log_sources_display    = array_filter([
        $error_log_path_display,
        $wp_root . '/wp-content/debug.log',
        $wp_root . '/error_log',
        $wp_root . '/error.log',
    ]);

    foreach ($log_sources_display as $log_path) {
        if (!empty($log_path) && file_exists($log_path) && is_readable($log_path)):
            $log_lines = tail_file($log_path, 30);
            if (!empty($log_lines)):
    ?>
    <div class="section-card">
        <table>
            <thead>
                <?= section_header('30 Baris Terakhir Error Log: ' . basename($log_path), '📄') ?>
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
            break;
        endif;
    }
    ?>

    <!-- FOOTER -->
    <div class="footer">
        <p>
            <strong>WordPress Hosting Diagnostic Tool v2.0</strong> &nbsp;|&nbsp;
            PHP <?= PHP_VERSION ?> &nbsp;|&nbsp;
            <?= htmlspecialchars($_SERVER['SERVER_NAME'] ?? 'localhost') ?>
        </p>
        <p style="margin-top:6px; color:#dc3545; font-weight:600;">
            ⚠️ Hapus file <code>wp-diagnose.php</code> ini segera setelah diagnosa selesai!
        </p>
    </div>

</div><!-- /.wrapper -->
</body>
</html>
