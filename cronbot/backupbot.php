<?php
chdir(__DIR__);
require_once '../config.php';
require_once '../function.php';
$textbotlang = languagechange();
require_once '../botapi.php';

function backupCollectFiles($source, $localName, &$map)
{
    if (is_file($source)) {
        $map[$localName] = $source;
        return;
    }
    if (!is_dir($source)) {
        return;
    }
    foreach (scandir($source) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        backupCollectFiles($source . '/' . $item, $localName . '/' . $item, $map);
    }
}

function backupCreateArchive(array $sources, $archiveBasePath)
{
    $map = [];
    foreach ($sources as $source) {
        if (file_exists($source)) {
            backupCollectFiles($source, basename($source), $map);
        }
    }
    if (!$map) {
        return null;
    }

    if (class_exists('ZipArchive')) {
        $zipPath = $archiveBasePath . '.zip';
        @unlink($zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($map as $localName => $filePath) {
                $zip->addFile($filePath, $localName);
            }
            $zip->close();
            if (is_file($zipPath) && filesize($zipPath) > 0) {
                return $zipPath;
            }
            @unlink($zipPath);
        }
    }

    if (isShellExecAvailable()) {
        $zipPath = $archiveBasePath . '.zip';
        @unlink($zipPath);
        $arguments = '';
        foreach ($sources as $source) {
            if (file_exists($source)) {
                $arguments .= ' ' . escapeshellarg($source);
            }
        }
        runShellCommand('zip -r ' . escapeshellarg($zipPath) . $arguments . ' 2>&1');
        if (is_file($zipPath) && filesize($zipPath) > 0) {
            return $zipPath;
        }
        @unlink($zipPath);
    }

    if (class_exists('PharData')) {
        $tarPath = $archiveBasePath . '.tar';
        $gzPath = $tarPath . '.gz';
        @unlink($tarPath);
        @unlink($gzPath);
        try {
            $phar = new PharData($tarPath);
            $phar->buildFromIterator(new ArrayIterator($map));
            $phar->compress(Phar::GZ);
            unset($phar);
            @unlink($tarPath);
            if (is_file($gzPath) && filesize($gzPath) > 0) {
                return $gzPath;
            }
        } catch (Exception $e) {
            error_log('PharData backup failed: ' . $e->getMessage());
            @unlink($tarPath);
            @unlink($gzPath);
        }
    }

    return null;
}

function backupDumpDatabaseWithPdo(PDO $pdo, $targetFile)
{
    $handle = @fopen($targetFile, 'w');
    if (!$handle) {
        error_log('Unable to open backup file for writing: ' . $targetFile);
        return false;
    }

    try {
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $table) {
            $name = '`' . str_replace('`', '``', $table[0]) . '`';
            $isView = isset($table[1]) && strtoupper($table[1]) === 'VIEW';
            $create = $pdo->query('SHOW CREATE TABLE ' . $name)->fetch(PDO::FETCH_NUM);
            fwrite($handle, 'DROP ' . ($isView ? 'VIEW' : 'TABLE') . ' IF EXISTS ' . $name . ";\n" . $create[1] . ";\n\n");
            if ($isView) {
                continue;
            }

            $offset = 0;
            $chunkSize = 500;
            do {
                $rows = $pdo->query('SELECT * FROM ' . $name . ' LIMIT ' . $chunkSize . ' OFFSET ' . $offset)->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) {
                    break;
                }
                $columns = [];
                foreach (array_keys($rows[0]) as $column) {
                    $columns[] = '`' . str_replace('`', '``', $column) . '`';
                }
                $values = [];
                foreach ($rows as $row) {
                    $cells = [];
                    foreach ($row as $cell) {
                        $cells[] = $cell === null ? 'NULL' : $pdo->quote((string) $cell);
                    }
                    $values[] = '(' . implode(',', $cells) . ')';
                }
                fwrite($handle, 'INSERT INTO ' . $name . ' (' . implode(',', $columns) . ") VALUES\n" . implode(",\n", $values) . ";\n");
                $offset += $chunkSize;
            } while (count($rows) === $chunkSize);
            fwrite($handle, "\n");
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    } catch (Exception $e) {
        error_log('PDO database backup failed: ' . $e->getMessage());
        fclose($handle);
        @unlink($targetFile);
        return false;
    }

    fclose($handle);

    return is_file($targetFile) && filesize($targetFile) > 0;
}

$reportbackup = select("topicid", "idreport", "report", "backupfile", "select")['idreport'];
$destination = getcwd();
// Backup artefacts must never be written inside the web root. getcwd() is
// cronbot/ (chdir above for the PHP CLI cron; the web server's cwd on older
// installs that still curl it), which is served over HTTP, so anything left
// beside it is fetchable for as long as it exists -- and the PharData fallback added
// upstream in 0.5.1 emits .tar.gz, which no <Files> rule used to cover.
// Build in the system temp dir; fall back to the web root only if that is
// unusable, where the .htaccess deny rules now catch it.
$backupWorkDir = sys_get_temp_dir();
if (!is_dir($backupWorkDir) || !is_writable($backupWorkDir)) {
    $backupWorkDir = $destination;
}
$setting = select("setting", "*");
$canSendReport = !isTelegramChatIdEmpty($setting['Channel_Report'] ?? '');
if (!$canSendReport) {
    return;
}
$sourcefir = dirname($destination);
$botlist = select("botsaz", "*", null, null, "fetchAll");
if ($botlist) {
    foreach ($botlist as $bot) {
        $botFolder = $sourcefir . '/vpnbot/' . $bot['id_user'] . $bot['username'];
        $archive = backupCreateArchive([
            $botFolder . '/data',
            $botFolder . '/product.json',
            $botFolder . '/product_name.json',
        ], $backupWorkDir . '/file');
        if ($archive === null) {
            continue;
        }
        telegram('sendDocument', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $reportbackup,
            'document' => new CURLFile($archive),
            'caption' => "@{$bot['username']} | {$bot['id_user']}",
        ]);
        unlink($archive);
    }
}

$backup_file_name = $backupWorkDir . '/backup_' . date("Y-m-d") . '.sql';
$dbhost = empty($dbhost) ? "localhost" : $dbhost;
$isDumped = false;

if (isExecAvailable()) {
    $command = 'mysqldump -h ' . escapeshellarg($dbhost)
        . ' -u ' . escapeshellarg($usernamedb)
        . ' -p' . escapeshellarg($passworddb)
        . ' --no-tablespaces --ssl-mode=DISABLED ' . escapeshellarg($dbname)
        . ' > ' . escapeshellarg($backup_file_name) . ' 2>/dev/null';
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    $isDumped = $return_var === 0 && is_file($backup_file_name) && filesize($backup_file_name) > 0;
    if (!$isDumped) {
        @unlink($backup_file_name);
    }
}

if (!$isDumped) {
    $isDumped = backupDumpDatabaseWithPdo($pdo, $backup_file_name);
}

if (!$isDumped) {
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $reportbackup,
        'text' => $textbotlang['keyboard']['backupError'],
    ]);
    return;
}

// [backup-gz] The raw dump easily exceeds Telegram's 50 MB bot upload
// limit and the shared telegram() helper aborts after 10 s, so the main
// backup used to vanish silently. Compress it, upload with a long timeout,
// split into parts if it is still too big, and report any failure.
function backupGzip($src)
{
    if (!function_exists('gzopen')) {
        return null;
    }
    $dst = $src . '.gz';
    $in = @fopen($src, 'rb');
    $out = @gzopen($dst, 'wb6');
    if (!$in || !$out) {
        return null;
    }
    while (!feof($in)) {
        gzwrite($out, fread($in, 1048576));
    }
    fclose($in);
    gzclose($out);
    return (is_file($dst) && filesize($dst) > 0) ? $dst : null;
}

function backupSplit($file, $partSize)
{
    if (filesize($file) <= $partSize) {
        return [$file];
    }
    $parts = [];
    $in = fopen($file, 'rb');
    $n = 1;
    while (!feof($in)) {
        $chunk = fread($in, $partSize);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $part = $file . '.part' . $n++;
        file_put_contents($part, $chunk);
        $parts[] = $part;
    }
    fclose($in);
    return $parts;
}

function backupSendDocument($apiKey, $chatId, $threadId, $path, $caption)
{
    $ch = curl_init('https://api.telegram.org/bot' . $apiKey . '/sendDocument');
    $fields = ['chat_id' => $chatId, 'document' => new CURLFile($path), 'caption' => $caption];
    if (!empty($threadId)) {
        $fields['message_thread_id'] = $threadId;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 900,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $res = json_decode((string) $raw, true);
    if (!empty($res['ok'])) {
        return true;
    }
    return $err !== '' ? $err : ($res['description'] ?? 'empty response');
}

$sendFile = backupGzip($backup_file_name);
if ($sendFile === null) {
    $sendFile = $backup_file_name;
} else {
    @unlink($backup_file_name);
}
$sizeMb = round(filesize($sendFile) / 1048576, 1);
$parts = backupSplit($sendFile, 45 * 1048576);
$caption = $textbotlang['Admin']['report']['backupCaption'] . "\n" . basename($sendFile) . " | {$sizeMb} MB";
$failed = [];
foreach ($parts as $i => $part) {
    $partCaption = count($parts) > 1
        ? $caption . "\n" . 'part ' . ($i + 1) . '/' . count($parts) . ' — cat ' . basename($sendFile) . '.part* > ' . basename($sendFile)
        : $caption;
    $result = backupSendDocument($APIKEY, $setting['Channel_Report'], $reportbackup, $part, $partCaption);
    if ($result !== true) {
        $failed[] = basename($part) . ': ' . $result;
    }
    if ($part !== $sendFile) {
        @unlink($part);
    }
}
@unlink($sendFile);
if ($failed) {
    error_log('[backup-gz] backup upload failed: ' . implode(' | ', $failed));
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $reportbackup,
        'text' => $textbotlang['keyboard']['backupError'] . "\n" . implode("\n", $failed),
    ]);
}
