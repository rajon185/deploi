<?php

namespace App\Scripts;

/** Helpers for safely generating bash. */
class Bash
{
    /** Single-quote a value for bash. */
    public static function q(string|int|null $value): string
    {
        return escapeshellarg((string) $value);
    }

    /**
     * Write a file on the server without any quoting headaches: the contents travel base64-encoded.
     */
    public static function writeFile(string $path, string $contents, string $mode = '644', ?string $owner = null): string
    {
        $b64 = base64_encode(str_replace("\r\n", "\n", $contents));
        $p = self::q($path);
        $dir = self::q(dirname($path));

        // Leading newline: safe to append after heredocs, which drop their final newline.
        $script = "\nmkdir -p {$dir}\necho '{$b64}' | base64 -d > {$p}\nchmod {$mode} {$p}\n";

        if ($owner) {
            $script .= 'chown '.self::q($owner.':'.$owner)." {$p}\n";
        }

        return $script;
    }

    /** Pipe SQL to a command (mysql / psql) without shell-escaping the SQL itself. */
    public static function pipeSql(string $sql, string $command): string
    {
        return "\necho '".base64_encode($sql)."' | base64 -d | {$command}\n";
    }

    public static function progress(int $percent, string $step): string
    {
        return "echo '##DEPLOI_PROGRESS:{$percent}:{$step}##'\n";
    }
}
