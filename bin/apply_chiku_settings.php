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

    const SQL_CHIKUNAME=<<<EOT
SELECT chikuname FROM chiku_mast WHERE chikucd=?
EOT;


    /** @var array $_line_settings */
    private $_line_settings = [0 => 'yubincd', 2 => 'chikucd', 3 => 'chikuname'];

    /** @var  string $_file_name */
    private $_file_name;
    /** @var  string $_file_path */
    private $_file_path;
    /** @var  string $_nickname */
    private $_nickname;
    /** @var  string $_clc */
    private $_clc;
    /** @var PDO $_db */
    private $_db;
    /** @var  array $_excel_contents */
    private $_excel_contents;

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
        try {
            $this->_db->query(sprintf('USE u%s%s', self::C_NEN, $this->_clc));
            echo "データベースへの接続成功。\n";
        } catch (Exception $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        // エクセルファイルの内容を取得
        if (!$this->getExcelContents()) {
            echo "エクセルファイルの内容取り込みに失敗しました。\n";
            exit;
        }

        // エクセルファイルの内容で整合性が取れているかを確認する
        if (!$this->checkExcelContents()) {
            echo "エクセルファイルの内容に確認すべきものがあります。\n";
            exit;
        }

        // 地区設定の相違をチェック
        if ($this->checkChikuSettings()){

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
            $this->_file_path = $file_path;
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

    /**
     * @return bool
     */
    private function getExcelContents()
    {
        // Excelリーダーのセット
        /** @var PHPExcel_IOFactory::load $objPHPExcel */
        $objPHPExcel = PHPExcel_IOFactory::load($this->_file_path);

        // シートの選択
        $objPHPExcel->setActiveSheetIndexByName('郵便番号データ');
        $objSheet = $objPHPExcel->getActiveSheet();

        $this->_excel_contents = [];

        // シートの内容を取得
        $is_first = true;
        foreach ($objSheet->getRowIterator() as $row) {
            if ($is_first) {
                $is_first = false;
            } else {
                $cells = [];
                foreach ($row->getCellIterator() as $index => $cell) {
                    if ($index > 3) {
                        break;
                    }
                    if (in_array($index, [0, 2, 3])) {
                        if ($cell) {
                            $cells[$this->_line_settings[$index]] = $cell->getValue();
                        }
                    }
                }
                // 必要な項目が揃っていたら取り込む
                if (count($cells) == 3) {
                    $this->_excel_contents[] = $cells;
                }
            }
        }

        // 内容が取得できていればtrue
        if ($this->_excel_contents) {
            return true;
        }
        return false;
    }

    private function checkExcelContents()
    {
        $ret = true;  // 戻し値

        // 郵便番号の重複チェック
        $yubincds = array_column($this->_excel_contents, 'yubincd');
        if (count($yubincds) !== count(array_unique($yubincds))) {
            if ($duplications = $this->checkDuplicateYubincd($yubincds)) {
                $file_path = __DIR__ . '/../data/郵便番号重複.txt';
                $fh = fopen($file_path, "w");
                foreach ($duplications as $line) {
                    fwrite($fh, $line . PHP_EOL);
                }
                fclose($fh);
                $ret = false;
            }
        }
        // 地区設定の重複チェック
        if ($duplications=$this->checkDuplicateSettings()){
            $file_path = __DIR__ . '/../data/地区設定重複.txt';
            $fh = fopen($file_path, "w");
            foreach ($duplications as $line) {
                fwrite($fh, $line . PHP_EOL);
            }
            fclose($fh);
            $ret = false;
        }

        return $ret;
    }

    /**
     * 重複している郵便番号とその行を返す
     * @param array $yubincds
     * @return array
     */
    private function checkDuplicateYubincd($yubincds)
    {
        $data = [];
        foreach ($yubincds as $index => $yubincd) {
            $data[$yubincd][] = $index;
        }
        $rtn = [];
        foreach ($data as $index => $datum) {
            if (count($datum) > 1) {
                $rtn[] = sprintf('郵便番号「%s」が複数の行[%s]に設定されています。', $index, implode(',', $datum));
            }
        }
        return $rtn;
    }

    private function checkDuplicateSettings()
    {
        $data = [];
        // 地区コードをキーにして地区名を配列に格納していく
        foreach ($this->_excel_contents as $excel_content) {
            $data[$excel_content['chikucd']][]=$excel_content['chikuname'];
        }
        $rtn = [];
        foreach ($data as $index => $datum) {
            // 重複を削除
            $chikunames=array_unique($datum);
            if (count($chikunames) > 1) {
                $rtn[] = sprintf('地区コード「%s」に対して複数の地区名[%s]が設定されています。', $index, implode(',', $chikunames));
            }
        }
        return $rtn;
    }

    private function checkChikuSettings()
    {
        // データベースの地区設定を取得する
        /** @var PDOStatement $sth */
        $sth = $this->_db->prepare(self::SQL_CHIKUNAME);

        // エクセルの地区設定を取得する
        $settings =array_filter (array_combine(array_column($this->_excel_contents, 'chikucd'), array_column($this->_excel_contents, 'chikuname')));
        ksort($settings);

        // 名称の設定違い
        foreach ($settings as $index => $setting) {
            $sth->execute([$index]);
            if ($str=$sth->fetch()){
                if ($str['chikuname']!=$setting){
                    echo "名称相違　axol=".$str['chikuname'].',excel='.$setting.PHP_EOL;
                }
            } else {
                echo "設定なし　コード=".$index.',名称='.$setting.PHP_EOL;
            }
        }


        return false;
    }


}

$apply_chiku_settings = new apply_chiku_settings();