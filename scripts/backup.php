<?php
/** CLI-only consistent SQL backup and private-file copy, outside the web root. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/environment.php';
/** Shared only with the provider-free backup regression suite. */
function writeBackupManifest(string $snapshot, string $dumpFile): void {
    $checksum=@hash_file('sha256',$dumpFile);
    if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/D',$checksum)) throw new RuntimeException('Database backup checksum failed.');
    $manifest=json_encode(['created_at'=>gmdate('c'),'database_sha256'=>$checksum,
        'notes'=>'Database and private uploads only. Protect configuration/signing keys and code release separately. Routines/events require a separate reviewed export.'],JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $manifestFile=$snapshot.'/manifest.json';
    $written=@file_put_contents($manifestFile,$manifest,LOCK_EX);
    if ($written!==strlen($manifest) || !@chmod($manifestFile,0600) || @file_get_contents($manifestFile)!==$manifest) throw new RuntimeException('Backup manifest could not be written and protected completely.');
}
if (defined('CONDO_BACKUP_FUNCTIONS_ONLY') && CONDO_BACKUP_FUNCTIONS_ONLY===true) return;
$directory = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--directory=')) $directory = substr($argument,12);
    else { fwrite(STDOUT,"Usage: php scripts/backup.php --directory=<private directory outside the web root>\n"); exit($argument==='--help' ? 0 : 2); }
}
if (!$directory) { fwrite(STDERR,"A private backup directory is required.\n"); exit(2); }
$root = realpath(dirname(__DIR__));
$publicRoot = realpath(dirname($root));
if (!is_dir($directory) && !mkdir($directory,0700,true)) { fwrite(STDERR,"Could not create backup directory.\n"); exit(1); }
$directory = realpath($directory);
$normalize = static fn(string $path): string => strtolower(str_replace('\\','/',rtrim($path,'/\\')));
if (!$directory || $normalize($directory)===$normalize($publicRoot) || str_starts_with($normalize($directory),$normalize($publicRoot).'/')) {
    fwrite(STDERR,"Backups must be outside the web root.\n"); exit(2);
}
$snapshot = $directory . DIRECTORY_SEPARATOR . 'snapshot_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
if (!mkdir($snapshot,0700)) { fwrite(STDERR,"Could not create snapshot.\n"); exit(1); }
$dumpFile = $snapshot . DIRECTORY_SEPARATOR . 'database.sql';
$binary = appSetting('CONDO_MYSQLDUMP_BINARY', PHP_OS_FAMILY==='Windows' ? dirname($root,2).'/mysql/bin/mysqldump.exe' : 'mysqldump');
$environment = array_merge(getenv(), ['MYSQL_PWD'=>appSetting('CONDO_DB_PASS')]);
$process = proc_open([$binary,'--single-transaction','--quick','--hex-blob','--triggers','--skip-routines','--skip-events',
    '--host='.appSetting('CONDO_DB_HOST','localhost'),'--user='.appSetting('CONDO_DB_USER','root'),
    '--result-file='.$dumpFile,appSetting('CONDO_DB_NAME','Condo_System')],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
if (!is_resource($process)) { fwrite(STDERR,"Could not launch database backup tool.\n"); exit(1); }
fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($process)!==0 || !is_file($dumpFile) || filesize($dumpFile)===0) {
    fwrite(STDERR,"Database backup failed. The snapshot is incomplete and must not be used for restoration.\n"); exit(1);
}
if (!chmod($dumpFile,0600)) {
    fwrite(STDERR,"Database backup permissions could not be protected. The snapshot is incomplete.\n"); exit(1);
}
function copyPrivateBackupFiles(string $source, string $target): void {
    if (!mkdir($target,0700) && !is_dir($target)) throw new RuntimeException('Could not create private file snapshot.');
    foreach (new FilesystemIterator($source,FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isLink()) throw new RuntimeException('Resolve symbolic links before backing up private storage.');
        $destination=$target.DIRECTORY_SEPARATOR.$entry->getFilename();
        if ($entry->isDir()) copyPrivateBackupFiles($entry->getPathname(),$destination);
        elseif (!copy($entry->getPathname(),$destination)) throw new RuntimeException('Private file backup failed.');
        elseif (!chmod($destination,0600)) throw new RuntimeException('Private file permissions could not be protected.');
    }
}
try {
    if (is_dir($root.'/private_uploads')) copyPrivateBackupFiles($root.'/private_uploads',$snapshot.'/private_uploads');
    writeBackupManifest($snapshot,$dumpFile);
    echo 'Backup complete: ' . $snapshot . PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR,'Private-file or manifest backup failed; snapshot is incomplete and must not be restored.' . PHP_EOL); exit(1); }
