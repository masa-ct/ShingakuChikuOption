<?php
require_once __DIR__ . '/../util/Classes/PHPExcel.php';
date_default_timezone_set('Asia/Tokyo');

/**
 * Created by PhpStorm.
 * User: okazaki
 * Date: 2017/05/01
 * Time: 16:39
 */
class apply_chiku_settings
{
    const C_HOST = '192.168.2.164';
    const C_USER = 'root';
    const C_NEN = 18;

    const SQL_CLNAME = <<<EOT
SELECT clname,clc FROM client WHERE nickname=?
EOT;

    /** @var  string $_file_name */
    private $_file_name;
    /** @var  string $_nickname */
    private $_nickname;
    /** @var  string $_clc */
    private $_clc;
    /** @var PDO $_db */
    private $_db;

    /**
     * apply_chiku_settings constructor.
     */
    public function __construct()
    {
        // 引数の処理
        $opts = getopt('hf:n:');
        if (!$opts
            || !array_key_exists('f', $opts)
            || !array_key_exists('n', $opts)
            || (array_key_exists('h', $opts))
        ) {
            print("*===============================================================================\n");
            print("* 地区オプション更新　オプション一覧\n*\n");
            print("*   -f : (file) 必須。\n");
            print("*                      マイナビからのエクセルファイルを指定。\n");
            print("*   -n : (nickname) 必須。\n");
            print("*                      対象学校のnicknameを指定。\n");
            print("*===============================================================================\n");
            exit;
        }

        // パラメータの値をセット
        $this->_file_name = $opts['f'];
        $this->_nickname = $opts['n'];

        // ファイルの調査
        if (false === $this->setFileName()) {
            echo "処理を中止します。\n";
            exit;
        }

        // サーバーへの接続
        try {
            // パスワードの入力
            fwrite(STDERR, 'サーバーのパスワードを入力してください: ');
            if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                // WindowsではエコーバックをOFFにできない
                @flock(STDIN, LOCK_EX);
                $pass = trim(fgets(STDIN));
                @flock(STDIN, LOCK_UN);
            } else {
                system('stty -echo');   // エコーバックをOFFにする
                @flock(STDIN, LOCK_EX);
                $pass = trim(fgets(STDIN));
                @flock(STDIN, LOCK_UN);
                system('stty echo');    // エコーバックをONに戻す
            }
            fwrite(STDERR, "\n");

            $dsn = sprintf('mysql:host=%s;dbname=%suadmin', self::C_HOST, self::C_NEN);
            $this->_db = new PDO($dsn, self::C_USER, $pass);
            $this->_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            echo "接続成功\n";
        } catch (Exception $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        // 入力されたnicknameから学校名を確認
        if (!$this->confirmNickName()) {
            echo "処理を中止します。\n";
            exit;
        }

        // データベースに接続
        try{
            $this->_db->query(sprintf('USE u%s%s',self::C_NEN,$this->_clc));
            echo "データベースへの接続成功。\n";
        }catch (Exception $e){
            echo $e->getMessage().PHP_EOL;
        }
    }

    /**
     * ファイル名の編集と存在確認
     * @return bool
     */
    public function setFileName()
    {
        // ファイルのパスを編集
        $file_path = realpath(__DIR__ . '/../' . $this->_file_name);

        // ファイルの存在確認
        if (is_file($file_path)) {
            return true;
        } else {
            echo sprintf("指定されたファイル「」が存在しません。\n", $this->_file_name);
        }
        return false;
    }

    /**
     * クライアント名を確認
     * @return bool
     */
    private function confirmNickName()
    {
        // nicknameからクライアント名を取得
        $sth = $this->_db->prepare(self::SQL_CLNAME);
        $sth->execute([$this->_nickname]);
        if ($str = $sth->fetch()) {
            // クライアント名を確認
            echo(sprintf('処理をする学校は%sであっていますか?(y/N)', $str['clname']));
            while (true) {
                $input = fgets(STDIN, 10);
                $input = rtrim($input, "\n");
                if ($input === 'y') {
                    $this->_clc = $str['clc'];
                    return true;
                } else {
                    return false;
                }
            }
        } else {
            echo sprintf("入力されたnickname「%s」に該当するものがありませんでした。\n", $this->_nickname);
        }

        return false;
    }

}

$apply_chiku_settings = new apply_chiku_settings();