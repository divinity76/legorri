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
            throw new \RuntimeException("Failed to unlink {$path}: " . var_export(error_get_last(), true));
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
            echo "unlink: {$file}\n";
        }
        if (!unlink($file)) {
            throw new \RuntimeException("Failed to unlink {$file}: " . var_export(error_get_last(), true));
        }
    }
    if ($verbose) {
        echo "rmdir: {$path}\n";
    }
    if (!rmdir($path)) {
        throw new \RuntimeException("Failed to rmdir {$path}: " . var_export(error_get_last(), true));
    }
}

function copyRecursive(string $source, string $destination, bool $verbose): void
{
    if (is_file($source)) {
        if ($verbose) {
            echo "copy: {$source} -> {$destination}\n";
        }
        if (!copy($source, $destination)) {
            throw new \RuntimeException("Failed to copy {$source} to {$destination}: " . var_export(error_get_last(), true));
        }
        return;
    }
    if (!is_dir($destination)) {
        if ($verbose) {
            echo "mkdir: {$destination}\n";
        }
        if (!mkdir($destination)) {
            throw new \RuntimeException("Failed to create folder {$destination}: " . var_export(error_get_last(), true));
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
        copyRecursive($sourcePath, $destinationPath);
    }
}

class LegoRRCompiler
{
    private $build_dir;
    private $assert_dir;
    function __construct()
    {
        //
    }
    function compile(): void
    {
        $build_dir = $this->build_dir =  __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "build_dir";
        echo "clearing build_dir: {$build_dir}\n";
        unlinkRecursive($build_dir, true);
        if (!mkdir($build_dir, 0777, true)) {
            throw new \RuntimeException("Failed to create build_dir: {$build_dir}");
        }
        $this->build_dir = realpath($build_dir);
        $this->assert_dir = realpath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "assets");
        assert(!!$this->asset_dir);
        $this->add_base_game();
    }
    private function add_base_game(): void
    {
        $build_dir = $this->build_dir;
        $base_game_dir = $this->assert_dir . DIRECTORY_SEPARATOR . "base_game";
        echo "copying base_game: {$base_game_dir} to {$build_dir}\n";
        $this->copy_dir($base_game_dir, $build_dir);
    }
}
$compiler = new LegoRRCompiler();
$compiler->compile();
