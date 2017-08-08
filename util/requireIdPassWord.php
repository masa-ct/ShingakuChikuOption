<?php

/**
 * 入力を求め、非表示で入力をさせるクラス
 * Class requireIdPassWord
 */
class requireIdPassWord
{
    /**
     * 入力内容を非表示で行わせる
     * @param string $host
     * @param string $item
     * @return bool|string
     */
    public static function getParam($host, $item)
    {
        // 入力を求める
        $input = false;
        fwrite(STDERR, sprintf('%sの%sを入力してください', $host, $item));
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            // WindowsではエコーバックをOFFにできない
            @flock(STDIN, LOCK_EX);
            $input = trim(fgets(STDIN));
            @flock(STDIN, LOCK_UN);
        } else {
            system('stty -echo');   // エコーバックをOFFにする
            @flock(STDIN, LOCK_EX);
            $input = trim(fgets(STDIN));
            @flock(STDIN, LOCK_UN);
            system('stty echo');    // エコーバックをONに戻す
        }
        fwrite(STDERR, "\n");

        return $input;
    }
}