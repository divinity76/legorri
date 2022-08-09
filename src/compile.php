<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (error_reporting() & $errno) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
});
if (!function_exists("dd")) {
    function dd(...$args)
    {
        $file = debug_backtrace()[0]['file'];
        $line = debug_backtrace()[0]['line'];
        echo "dd: {$file}:{$line}\n";
        var_dump(...$args);
        die();
    }
}
/**
 * delete a file or directory
 * automatically traversing directories if needed.
 * PS: has not been tested with self-referencing symlink shenanigans, that might cause a infinite recursion, i don't know.
 * 
 * @param string $cmd
 * @throws \RuntimeException if unlink fails
 * @throws \RuntimeException if rmdir fails
 * @return void
 */
function unlinkRecursive(string $path, bool $verbose = false): void
{
    if (!is_readable($path)) {
        return;
    }
    if (is_file($path)) {
        if ($verbose) {
            echo "unlink: {$path}\n";
        }
        if (!unlink($path)) {
            throw new \RuntimeException("Failed to unlink \"{$path}\": " . var_export(error_get_last(), true));
        }
        return;
    }
    $foldersToDelete = array();
    $filesToDelete = array();
    // we should scan the entire directory before traversing deeper, to not have open handles to each directory:
    // on very large director trees you can actually get OS-errors if you have too many open directory handles.
    foreach (new DirectoryIterator($path) as $fileInfo) {
        if ($fileInfo->isDot()) {
            continue;
        }
        if ($fileInfo->isDir()) {
            $foldersToDelete[] = $fileInfo->getRealPath();
        } else {
            $filesToDelete[] = $fileInfo->getRealPath();
        }
    }
    unset($fileInfo); // free file handle
    foreach ($foldersToDelete as $folder) {
        unlinkRecursive($folder, $verbose);
    }
    foreach ($filesToDelete as $file) {
        if ($verbose) {
            echo "unlink: \"{$file}\"\n";
        }
        if (!unlink($file)) {
            throw new \RuntimeException("Failed to unlink \"{$file}\": " . var_export(error_get_last(), true));
        }
    }
    if ($verbose) {
        echo "rmdir: {$path}\n";
    }
    if (!rmdir($path)) {
        throw new \RuntimeException("Failed to rmdir \"{$path}\": " . var_export(error_get_last(), true));
    }
}

function copyRecursive(string $source, string $destination, bool $verbose = false): void
{
    if (is_file($source)) {
        if ($verbose) {
            echo "copy: \"{$source}\" -> \"{$destination}\"\n";
        }
        if (!copy($source, $destination)) {
            throw new \RuntimeException("Failed to copy \"{$source}\" to \"{$destination}\": " . var_export(error_get_last(), true));
        }
        return;
    }
    if (!is_dir($destination)) {
        if ($verbose) {
            echo "mkdir: {$destination}\n";
        }
        if (!mkdir($destination)) {
            throw new \RuntimeException("Failed to create folder \"{$destination}\": " . var_export(error_get_last(), true));
        }
    }
    $copyList = [];
    foreach (new DirectoryIterator($source) as $fileInfo) {
        if ($fileInfo->isDot()) {
            continue;
        }
        $sourcePath = $fileInfo->getRealPath();
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $fileInfo->getFilename();
        $copyList[] = array($sourcePath, $destinationPath);
    }
    foreach ($copyList as list($sourcePath, $destinationPath)) {
        copyRecursive($sourcePath, $destinationPath, $verbose);
    }
}
function isCygwin(): bool
{
    return (PHP_OS === "CYGWIN");
}

class LegoRRCompiler
{
    private $build_dir;
    private $assert_dir;
    function __construct()
    {
        //
    }
    public function createSetupExe(bool $fast)
    {
        $exe7zSearch = array(
            "C:\\Program Files\\7-Zip\\7z.exe",
            "C:\\Program Files (x86)\\7-Zip\\7z.exe",
            "C:\\Program Files (x86)\\7-Zip\\7z64.exe",
            "/usr/bin/7z",
        );
        $exe7z = null;
        foreach ($exe7zSearch as $exe7zTest) {
            if (file_exists($exe7zTest)) {
                $exe7z = $exe7zTest;
                break;
            }
        }
        if ($exe7z === null) {
            throw new \RuntimeException("7-Zip 7z not found");
        }
        $build_dir = $this->build_dir;
        if (isCygwin()) {
            $exe7z = trim(shell_exec("cygpath --unix " . escapeshellarg($exe7z)));
            $build_dir = trim(shell_exec("cygpath --windows " . escapeshellarg($build_dir)));
        }
        $setup_exe_name = "legorri_setup_" . date("Y_m_d") . ".exe";
        $cmd = array(
            escapeshellarg($exe7z),
            "a",
            // not sure what all these flags means, source: https://superuser.com/a/1449735/519577
            ($fast ? "-mx=1" : "-t7z -mx=9 -mfb=273 -ms -md=31 -myx=9 -mtm=- -mmt -mmtf -md=1536m -mmf=bt3 -mmc=10000 -mpb=0 -mlc=0"),
            //"-sfx7z.sfx",
            "-sfx",
            escapeshellarg($setup_exe_name),
            escapeshellarg($build_dir),
        );
        $cmd = implode(" ", $cmd);
        //dd($exe7z, $build_dir, $cmd);
        echo "Executing: {$cmd}\n";
        passthru($cmd, $return);
        if ($return !== 0) {
            throw new \RuntimeException("7z Failed to create setup.exe! error code: {$return}");
        }
    }

    public function compile(): void
    {
        chdir(__DIR__ . DIRECTORY_SEPARATOR . "..");
        $build_dir = $this->build_dir =  __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "legorr";
        echo "clearing build_dir: \"{$build_dir}\"\n";
        unlinkRecursive($build_dir, true);
        if (!mkdir($build_dir, 0777, true)) {
            throw new \RuntimeException("Failed to create build_dir: \"{$build_dir}\"");
        }
        $this->build_dir = realpath($build_dir);
        $this->assert_dir = realpath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "assets");
        assert(!!$this->asset_dir);
        // sigh..
        $this->add_base_game();
        $this->add_d3drm();
        $this->remove_drm();
        $this->add_dgvoodoo();
        $this->add_music_fix();
        $this->add_llrce();
        $this->add_mod_manager_cafeteria();
        echo "game compiled!\n";
    }
    private function add_base_game(): void
    {
        $build_dir = $this->build_dir;
        $base_game_dir = $this->assert_dir . DIRECTORY_SEPARATOR . "base_game_with_drm";
        echo "copying base_game: \"{$base_game_dir}\" to \"{$build_dir}\"\n";
        copyRecursive($base_game_dir, $build_dir, true);
    }
    private function add_d3drm(): void
    {
        $build_dir = $this->build_dir;
        $d3drm_dir = $this->assert_dir . DIRECTORY_SEPARATOR . "d3drm";
        echo "adding d3drm: \"{$d3drm_dir}\" to \"{$build_dir}\"\n";
        copyRecursive($d3drm_dir, $build_dir, true);
    }
    private function remove_drm(): void
    {
        $build_dir = $this->build_dir;
        $drm_free_dir = $this->assert_dir . DIRECTORY_SEPARATOR . "LRR_Masterpiece_Editon_Executable_no_DRM";
        echo "adding DRM-free files: \"{$drm_free_dir}\" to \"{$build_dir}\"\n";
        copyRecursive($drm_free_dir, $build_dir, true);
    }
    private function add_music_fix(): void
    {
        $build_dir = $this->build_dir;
        $musc_fix_dir = $this->assert_dir . DIRECTORY_SEPARATOR . "LRR_music_fix_v1_1";
        echo "adding music fix: \"{$musc_fix_dir}\" to \"{$build_dir}\"\n";
        copyRecursive($musc_fix_dir, $build_dir, true);
    }
    private function add_dgvoodoo(): void
    {
        $build_dir = $this->build_dir;
        $dgvoodoo_dir = $this->assert_dir . DIRECTORY_SEPARATOR . "dgVoodoo2_79_1";
        $tmp = $dgvoodoo_dir . DIRECTORY_SEPARATOR . "MS" . DIRECTORY_SEPARATOR . "x86";
        echo "adding dgVoodoo: \"{$tmp}\" to \"{$build_dir}\"\n";
        copyRecursive($tmp, $build_dir, true);
        $tmp = $dgvoodoo_dir . DIRECTORY_SEPARATOR . "dgVoodoo.conf";
        echo "adding dgVoodoo.conf: \"{$tmp}\" to \"{$build_dir}\"\n";
        copyRecursive($tmp, $build_dir . DIRECTORY_SEPARATOR . "dgVoodoo.conf", true);
        $tmp = $dgvoodoo_dir . DIRECTORY_SEPARATOR . "dgVoodooCpl.exe";
        echo "adding dgVoodooCpl.exe: \"{$tmp}\" to \"{$build_dir}\"\n";
        copyRecursive($tmp, $build_dir . DIRECTORY_SEPARATOR . "dgVoodooCpl.exe", true);
    }
    private function add_llrce(): void
    {
        $build_dir = $this->build_dir;
        $lrr_ce_dir = $this->assert_dir . DIRECTORY_SEPARATOR . "LLRCE_v1.0.3.1";
        echo "adding LLRCE: \"{$lrr_ce_dir}\" to \"{$build_dir}\"\n";
        copyRecursive($lrr_ce_dir, $build_dir, true);
    }
    private function add_mod_manager_cafeteria(): void
    {
        $build_dir = $this->build_dir;
        $mm_cafeteria_dir = $this->assert_dir . DIRECTORY_SEPARATOR . "mod_manager_Cafeteria_v1.0BETA7";
        echo "adding Mod_Manager_Cafeteria: \"{$mm_cafeteria_dir}\" to \"{$build_dir}\"\n";
        copyRecursive($mm_cafeteria_dir, $build_dir, true);
    }
}
$compiler = (new LegoRRCompiler());
$compiler->compile();
$fast = false;
$compiler->createSetupExe($fast);
