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
    /** @var  PDOStatement $sth_select_chiku_mast */
    private $sth_select_chiku_mast;
    /** @var  PDOStatement $sth_update_chiku_mast */
    private $sth_update_chiku_mast;
    /** @var  PDOStatement $sth_insert_chiku_mast */
    private $sth_insert_chiku_mast;
    /** @var  PDOStatement $sth_update_chiku */
    private $sth_update_chiku;
    /** @var  PDOStatement $sth_insert_chiku */
    private $sth_insert_chiku;
    /** @var  PDOStatement $sth_count */
    private $sth_count;

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
            print("*   必要条件\n");
            print("*   シート名が「郵便番号データ」であること\n");
            print("*   格納・更新するデータのフィールド名が「郵便番号」「地区コード」「地区」であること。\n");
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
                if (count(array_filter($cells)) == 3) {
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
        if ($duplications = $this->checkDuplicateSettings()) {
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
            $data[$excel_content['chikucd']][] = $excel_content['chikuname'];
        }
        $rtn = [];
        foreach ($data as $index => $datum) {
            // 重複を削除
            $chikunames = array_unique($datum);
            if (count($chikunames) > 1) {
                $rtn[] = sprintf('地区コード「%s」に対して複数の地区名[%s]が設定されています。', $index, implode(',', $chikunames));
            }
        }
        return $rtn;
    }

    private function checkChikuSettings()
    {
        // 地区オプションを設定済みかどうかを取得する
        $is_chiku_option = $this->isChikuOption();

        // エクセルの地区設定を取得する
        $settings = array_filter(array_combine(array_column($this->_excel_contents, 'chikucd'), array_column($this->_excel_contents, 'chikuname')));
        ksort($settings);

        // 名称の設定違い
        foreach ($settings as $index => $setting) {
            // 地区コードから地区名を取得する
            $this->sth_select_chiku_mast->execute([$index]);
            if ($str = $this->sth_select_chiku_mast->fetch()) {
                if ($str['chikuname'] != $setting) {
                    echo "名称相違　axol=" . $str['chikuname'] . ',excel=' . $setting . PHP_EOL;
                    echo('地区名称の変更をしますか?(y/N)');
                    while (true) {
                        $input = fgets(STDIN, 10);
                        $input = rtrim($input, "\n");
                        if ($input === 'y') {
                            try {
                                $this->sth_update_chiku_mast->execute([$setting, $index]);
                                echo "更新をしました。\n";
                                break;
                            } catch (Exception $e) {
                                echo $e->getMessage() . PHP_EOL;
                            }
                        } else {
                            echo "処理を中止します。\n";
                            return false;
                        }
                    }
                }
            } else {
                // 地区オプション未設定の場合は無条件で追加していく
                if (!$is_chiku_option) {
                    $this->sth_insert_chiku_mast->execute([$index, $setting]);
                } else {
                    echo "設定なし　コード=" . $index . ',名称=' . $setting . PHP_EOL;
                    echo('地区の追加をしますか?(y/N)');
                    while (true) {
                        $input = fgets(STDIN, 10);
                        $input = rtrim($input, "\n");
                        if ($input === 'y') {
                            try {
                                $this->sth_insert_chiku_mast->execute([$index, $setting]);
                                echo "追加をしました。\n";
                                break;
                            } catch (Exception $e) {
                                echo $e->getMessage() . PHP_EOL;
                            }
                        } else {
                            echo "処理を中止します。\n";
                            return false;
                        }
                    }
                }
            }
        }

        // 地区設定の削除
        $chiku_settings = array_keys($settings);      // エクセルファイルに存在する地区コード
        $inClosure = substr(str_repeat(',?', count($chiku_settings)), 1);
        $sth_delete = $this->_db->prepare('DELETE FROM `chiku_mast` WHERE NOT `chikucd` IN (' . $inClosure . ')');
        $sth_delete->execute($chiku_settings);
        $this->sth_count->execute();
        if ($str = $this->sth_count->fetch(PDO::FETCH_NUM)) {
            echo sprintf("chiku_mastを%s件削除しました。\n", $str[0]);
        }

        return true;
    }

    private function updateChiku()
    {
        // 設定済み郵便番号を取得
        /** @var PDOStatement $sth */
        $sth = $this->_db->query('SELECT `yubincd`,`chikucd` FROM `chiku`');
        $exists = $sth->fetchAll();
        // yubincdをキーに、chikucdを値にする
        $exists = array_combine(array_column($exists, 'yubincd'), array_column($exists, 'chikucd'));

        // 追加
        $cnt_add = 0;
        $additions = array_diff(array_column($this->_excel_contents, 'yubincd'), array_keys($exists));
        if (count($additions) > 0) {
            foreach ($additions as $index => $addition) {
                // キーでエクセルの内容を呼び出して書き込み
                $this->sth_insert_chiku->execute([$this->_excel_contents[$index]['yubincd'], $this->_excel_contents[$index]['chikucd']]);
                $this->sth_count->execute();
                if ($str = $this->sth_count->fetch(PDO::FETCH_NUM)) {
                    $cnt_add = $cnt_add + $str[0];
                }
            }
            echo sprintf("chikuに%s件追加しました。\n", $cnt_add);
        }

        // 更新
        $cnt_update = 0;
        foreach ($this->_excel_contents as $excel_content) {
            if (array_key_exists($excel_content['yubincd'], $exists)) {
                if ($excel_content['chikucd'] != $exists[$excel_content['yubincd']]) {
                    $this->sth_update_chiku->execute([$excel_content['chikucd'], $excel_content['yubincd']]);
                    $this->sth_count->execute();
                    if ($str = $this->sth_count->fetch(PDO::FETCH_NUM)) {
                        $cnt_update = $cnt_update + $str[0];
                    }
                }
            }
        }
        echo sprintf("chikuを%s件更新しました。\n", $cnt_update);

        // 削除
        $current_data = array_column($this->_excel_contents, 'yubincd');
        $inClose = substr(str_repeat(',?', count($current_data)), 1);
        $sth_delete = $this->_db->prepare('DELETE FROM `chiku` WHERE NOT `yubincd` IN (' . $inClose . ')');
        $sth_delete->execute($current_data);
        $this->sth_count->execute();
        if ($str = $this->sth_count->fetch(PDO::FETCH_NUM)) {
            echo sprintf("chikuを%s件削除しました。\n", $str[0]);
        }
    }

    private function setSQL()
    {
        // chiku_mast
        $this->sth_select_chiku_mast = $this->_db->prepare('SELECT `chikuname` FROM `chiku_mast` WHERE `chikucd` = ?');
        $this->sth_update_chiku_mast = $this->_db->prepare('UPDATE `chiku_mast` SET `chikuname` = ? WHERE `chikucd` = ?');
        $this->sth_insert_chiku_mast = $this->_db->prepare('INSERT INTO `chiku_mast`(`chikucd`,`chikuname`,`created_at`) VALUES(?,?,NOW())');

        // chiku
        $this->sth_update_chiku = $this->_db->prepare('UPDATE `chiku` SET `chikucd` = ? WHERE `yubincd` = ?');
        $this->sth_insert_chiku = $this->_db->prepare('INSERT INTO `chiku`(`yubincd`,`chikucd`,`created_at`) VALUES(?,?,NOW())');

        // count
        $this->sth_count = $this->_db->prepare('SELECT ROW_COUNT()');
    }

    public function run()
    {
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

        // 各PDOstatementの設定
        $this->setSQL();

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
        if (!$this->checkChikuSettings()) {
            echo "地区の設定に問題がありました。\n";
            exit;
        }

        // 地区の更新
        $this->updateChiku();
    }

    private function isChikuOption()
    {
        $ret = false;

        // set_kaisを調べる
        $sth = $this->_db->query('SELECT `value` FROM `set_kais` WHERE `item` = \'is_chiku\'');
        if ($str = $sth->fetch(PDO::FETCH_ASSOC)) {
            $ret = ('1' == $str['value']);
            // 地区オプション使用状態にする
            $this->_db->query('UPDATE `set_kais` SET `value` = \'1\' WHERE `item` = \'is_chiku\'');
        }
        return $ret;
    }

}

$apply_chiku_settings = new apply_chiku_settings();
$apply_chiku_settings->run();