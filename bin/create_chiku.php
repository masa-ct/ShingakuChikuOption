<?php
set_include_path(__DIR__ . '/../util/Classes');
include_once('PHPExcel.php');
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Tokyo');
error_reporting(E_ALL);

class createChiku
{
    /**
     * @var PDO $db
     */
    private $db;
    private $lines;
    private $clientname;

    const C_HOST = 'ono';
    const C_PORT = "3306";
    const C_USER = "tap";
    private $prefectures;

    public function __construct()
    {
        // 引数の処理
        $opts = getopt('hp:');
        if (!$opts || (array_key_exists('h', $opts))) {
            print("*===============================================================================\n");
            print("* 地区オプション用郵便番号データ作成　オプション一覧\n*\n");
            print("*   -p : (prefectures) 必須。\n");
            print("*                      対象とする都道府県を、都道府県名あるいはコードで指定します。\n");
            print("*                      名称とコードの混在可。　カンマ「,」で連結してください。\n*\n");
            print("*===============================================================================\n");
            exit;
        }

        $this->setDb();
        $this->getClientname();

        // パラメータで与えられた都道府県を配列にしてセットする
        try {
            $this->setPrefectures($opts['p']);
        } catch (Exception $e) {
            echo $e->getMessage();
            exit;
        }
        print_r($this->prefectures);
        exit;

        // データ保存領域の初期化
        $this->lines = array();

    }

    /**
     * @param $file_excel
     * @param $lines
     */
    function createExcel($file_excel, $lines)
    {

        // 基になるファイルを読み込む
        $objPHPExcel = PHPExcel_IOFactory::load('org/original_data.xlsx');

        // シートの選択
        $objPHPExcel->setActiveSheetIndex(3);
        $objSheet = $objPHPExcel->getActiveSheet();

        // 見出し行を固定
        $objSheet->freezePane('A2');

        // データを流し込んでいく
        foreach ($lines as $gyou => $line) {
            foreach ($line as $retsu => $data) {
                if ($retsu == 0) {
                    // A列の郵便番号は、文字列としてデータをセットする
                    $objSheet->setCellValueExplicitByColumnAndRow(
                        $retsu,
                        $gyou + 1,
                        $data,
                        PHPExcel_Cell_DataType::TYPE_STRING
                    );
                } else {
                    $objSheet->getCellByColumnAndRow($retsu, $gyou + 1)->setvalue($data);
                }
            }
        }

        $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
        $objWriter->save($file_excel);
        unset($objWriter);
        unset($objSheet);
        unset($objPHPExcel);
    }

    function add_ken($files, $db, &$lines)
    {
        // 都道府県名の格納
        $fuken = array();
        /** @var PDO $db */
        $sth = $db->query('select cd,name from common_u.fuken order by cd');
        while ($str = $sth->fetch()) {
            $fuken[$str['cd']] = $str['name'];
        }

        // 県コードを指定して高校マスタを読み込む
        $sth = $db->prepare(
            "select replace(yubin, '-', '') as yubin,fullname,jusho from koko
	    where ken=? and jusho<> '' and fumei<> '3' order by yubin"
        );

        $naiyo = array();
        $naiyo_koko = array();

        // 連結スイッチ
        $join = false;

        $max = 0;

        foreach ($files as $file) {
            // 読み込みファイル
            $fp = fopen($file, "r");
            $chou = '';

            while ($str = fgets($fp)) {
                $str = mb_convert_encoding($str, "utf-8", "sjis-win");
                $str = str_replace('"', '', $str);
                $data = explode(",", $str);

                // 郵便番号をキーに
                $key = sprintf("%07d", $data[2]);
                if (!array_key_exists($key, $naiyo)) {
                    $naiyo[$key]['ken'] = array();
                    $naiyo[$key]['jichi'] = array();
                    $naiyo[$key]['chou'] = array();
                }

                // 各配列に値を追加していく
                if (!in_array($data[6], $naiyo[$key]['ken'])) {
                    $naiyo[$key]['ken'][] = $data[6];
                }

                if (!in_array($data[7], $naiyo[$key]['jichi'])) {
                    $naiyo[$key]['jichi'][] = $data[7];
                }

                // 町域名の中に全角の小かっこが入っていたら文字の連結をするスイッチを入れる
                if (strpos($data[8], "（") !== false) {
                    $join = true;
                    $chou = "";
                }

                if ($join === true) {
                    $chou .= $data[8];
                    if (strpos($chou, "）") !== false) {
                        $join = false;
                        $naiyo[$key]['chou'][] = $chou;
                        $chou = "";
                    }
                } else {
                    if (!in_array($data[8], $naiyo[$key]['chou'])) {
                        $naiyo[$key]['chou'][] = $data[8];
                    }
                }

                $max = (count($naiyo[$key]['chou']) > $max) ? count($naiyo[$key]['chou']) : $max;
            }

            fclose($fp);

            // ファイル名から県コードの取得
            if (preg_match('/\/(\d{2})[^\/]+.csv$/', $file, $regs)) {
                $kencd = $regs[1];
                /** @var PDOstatement $sth */
                $sth->execute(array($kencd));
                while ($str = $sth->fetch()) {
                    if (!array_key_exists($str['yubin'], $naiyo)) {
                        $naiyo_koko[$str['yubin']]['fuken'] = $fuken[(int)$kencd];
                        $naiyo_koko[$str['yubin']]['city'] = $str['fullname'];
                        $naiyo_koko[$str['yubin']]['chou'] = $str['jusho'];
                    }
                }
            }

        }

        // 書き出し
        // 最初に項目列を書き出す
        $gyou = "郵便番号\t都道府県\t市区町村";
        for ($i = 1; $i <= $max; $i++) {
            $gyou = $gyou . "\t地名" . $i;
        }
        $gyou .= "\n";

        $lines[] = explode("\t", trim($gyou));    // エクセル書き出しのために保存

        // ここから内容の書き出し
        foreach ($naiyo as $key => $str) {
            $ken = implode("・", $str['ken']);
            $jichi = implode("・", $str['jichi']);
            $chou = implode("\t", $str['chou']);
            $gyou = sprintf("%s\t%s\t%s\t%s\n", $key, $ken, $jichi, $chou);

            $lines[] = explode("\t", trim($gyou)); // エクセル書き出しのために保存
        }

        // ここから独自郵便番号の高校の書き出し
        foreach ($naiyo_koko as $key => $str) {
            $gyou = sprintf("%s\t%s\t%s\t%s\n", $key, $str['fuken'], $str['city'], $str['chou']);

            $lines[] = explode("\t", trim($gyou)); // エクセル書き出しのために保存
        }
    }

    function getZip()
    {
        // このフォルダ内の郵便番号データ(zip)を取得
        // ディレクトリ・ハンドルを開く
        $dirname = dirname(__DIR__) . '/data/';
        $dir = opendir($dirname);
        $files = array();
        $csv_files = array();

        // ディレクトリ内のファイルを取得
        while ($file = readdir($dir)) {
            // 郵便番号データのファイル名を配列に保存
            if (preg_match("/^\d{2}[a-z]+\.zip$/", $file)) {
                $filepath = $dirname . $file;
                $key = date("YmdHis", filemtime($filepath)) . $file;
                $files[$key] = $filepath;
            }
        }

        ksort($files);
        foreach ($files as $file) {
            // 解凍した時にできるファイル名を編集
            $csv = str_replace("zip", "csv", $file);
            $csv_files[] = $csv;
        }

        // 既存のcsvファイルを削除
        foreach ($csv_files as $csv) {
            if (is_file($csv)) {
                unlink($csv);
            }
        }

        // 圧縮ファイルを解凍
        foreach ($files as $file) {
            $cmd = sprintf("unzip %s -d %s", $file, $dirname);
            echo $cmd . "\n";
            exec($cmd);
        }

        // csvファイルの存在確認
        foreach ($csv_files as $key => $csv) {
            if (!is_file($csv)) {
                unset($csv_files[$key]);
            }
        }

        // テキストファイルの書き出し
        $this->add_ken($csv_files, $this->db, $this->lines);

        // エクセル書き出し
        $filename = __DIR__ . '/../data/【地区オプション】' . $this->clientname . '郵便番号一覧.xlsx';
        createExcel($filename, $this->lines);

        // csvファイルの削除
        foreach ($csv_files as $csv) {
            if (is_file($csv)) {
                unlink($csv);
            }
        }

    }

    /**
     * 学校名の入力
     */
    public function getClientname()
    {
        fwrite(STDERR, '学校名: ');
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            // WindowsではエコーバックをOFFにできない
            @flock(STDIN, LOCK_EX);
            $this->clientname = trim(fgets(STDIN));
            @flock(STDIN, LOCK_UN);
        } else {
            @flock(STDIN, LOCK_EX);
            $this->clientname = trim(fgets(STDIN));
            @flock(STDIN, LOCK_UN);
        }
        fwrite(STDERR, "\n");
    }

    /**
     * @internal param PDO $db
     */
    public function setDb()
    {
        // サーバーのパスワード入力
        fwrite(STDERR, 'onoのPassword: ');
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            // WindowsではエコーバックをOFFにできない
            @flock(STDIN, LOCK_EX);
            $password = trim(fgets(STDIN));
            @flock(STDIN, LOCK_UN);
        } else {
            system('stty -echo');   // エコーバックをOFFにする
            @flock(STDIN, LOCK_EX);
            $password = trim(fgets(STDIN));
            @flock(STDIN, LOCK_UN);
            system('stty echo');    // エコーバックをONに戻す
        }
        fwrite(STDERR, "\n");

        $dsn = sprintf("mysql:host=%s;port=%s;dbname=17uadmin", static::C_HOST, static::C_PORT);
        $this->db = new PDO($dsn, static::C_USER, $password);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    }

    /**
     * @param string $prefectures
     * @throws Exception
     */
    public function setPrefectures($prefectures)
    {
        $sql = <<<EOM
select * from common_u.fuken where shortname = ?
EOM;
        $this->db->query('use common_u');
        /*
         * 都道府県名からコードを取得する
         */
        $sth = $this->db->prepare($sql);

        /* パラメータの値をカンマで分割し、数値の場合は都道府県コードとしての妥当性を確認した
         * 上で配列に格納し、そうでない文字列の場合はコード化して配列に格納する。
         */
        $this->prefectures = [];
        $values = explode(',', $prefectures);

        foreach ($values as $value) {
            if (is_numeric($value)) {
                // 数値の場合
                $num = (int)$value;
                if ($num > 1 && $num < 48) {
                    $this->prefectures[$num] = '';
                }
            } else {
                // 文字列の場合
                $sth->execute([$value]);
                if ($str = $sth->fetch(PDO::FETCH_ASSOC)) {
                    $this->prefectures[$str['cd']] = '';
                }
            }
        }

        if (count($this->prefectures) == 0) {
            throw new Exception('都道府県に該当なし'.PHP_EOL);
        }
    }

    private $files = array(
        1 => '01hokkai.zip',
        2 => '02aomori.zip',
        3 => '03iwate.zip',
        4 => '04miyagi.zip',
        5 => '05akita.zip',
        6 => '06yamaga.zip',
        7 => '07fukush.zip',
        8 => '08ibarak.zip',
        9 => '09tochig.zip',
        10 => '10gumma.zip',
        11 => '11saitam.zip',
        12 => '12chiba.zip',
        13 => '13tokyo.zip',
        14 => '14kanaga.zip',
        15 => '15niigat.zip',
        16 => '16toyama.zip',
        17 => '17ishika.zip',
        18 => '18fukui.zip',
        19 => '19yamana.zip',
        20 => '20nagano.zip',
        21 => '21gifu.zip',
        22 => '22shizuo.zip',
        23 => '23aichi.zip',
        24 => '24mie.zip',
        25 => '25shiga.zip',
        26 => '26kyouto.zip',
        27 => '27osaka.zip',
        28 => '28hyogo.zip',
        29 => '29nara.zip',
        30 => '30wakaya.zip',
        31 => '31tottor.zip',
        32 => '32shiman.zip',
        33 => '33okayam.zip',
        34 => '34hirosh.zip',
        35 => '35yamagu.zip',
        36 => '36tokush.zip',
        37 => '37kagawa.zip',
        38 => '38ehime.zip',
        39 => '39kochi.zip',
        40 => '40fukuok.zip',
        41 => '41saga.zip',
        42 => '42nagasa.zip',
        43 => '43kumamo.zip',
        44 => '44oita.zip',
        45 => '45miyaza.zip',
        46 => '46kagosh.zip',
        47 => '47okinaw.zip'
    );

}

$create_chiku = new createChiku();





